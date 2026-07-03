<?php

namespace App\TravelPlanner\Services;

use App\TravelPlanner\Agents\AttractionAgent;
use App\TravelPlanner\Agents\HotelAgent;
use App\TravelPlanner\Agents\ItineraryOptimizerAgent;
use App\TravelPlanner\Agents\TransportAgent;
use App\TravelPlanner\Agents\WeatherAgent;
use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\TravelPlan;
use App\TravelPlanner\DTO\UserRequest;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Http;
use Throwable;

final class RootTravelPlanner
{
    public function __construct(
        private readonly WeatherAgent $weatherAgent,
        private readonly TransportAgent $transportAgent,
        private readonly HotelAgent $hotelAgent,
        private readonly AttractionAgent $attractionAgent,
        private readonly ItineraryOptimizerAgent $itineraryOptimizer,
        private readonly LocationResolver $locations,
        private readonly TravelAdvisor $advisor,
    ) {
    }

    public function run(UserRequest $request): TravelPlan
    {
        [$weather, $transport, $hotels, $orchestration] = $this->runCoreAgents($request);
        $attractions = $this->attractionAgent->run($request, $weather, $this->hotelContext($hotels));
        $transportRecommendations = $this->markPrimary($this->advisor->adviseTransport($request, $transport->recommendations));
        $hotelRecommendations = $this->advisor->adviseHotels($request, $hotels->recommendations);
        $attractionRecommendations = $this->advisor->adviseAttractions($request, $attractions->recommendations, $weather->summary);
        $optimized = $this->itineraryOptimizer->run($request, $weather->summary, $transportRecommendations, $hotelRecommendations, $attractionRecommendations);

        $origin = $this->locations->resolve($request->origin)['canonical_name'];
        $destination = $this->locations->resolve($request->destination)['canonical_name'];
        $cost = $optimized['total_cost'];
        $breakdown = $optimized['budget_breakdown'];
        $advisor = $this->advisor->buildAdvisorSummary($request, $transportRecommendations, $hotelRecommendations, $attractionRecommendations, $cost);
        $fallbackSummary = $advisor['summary']."\n".$this->summary($request, $origin, $destination, $weather, $transportRecommendations, $hotelRecommendations, $attractionRecommendations, $cost, $breakdown);
        $summary = $this->buildFinalSummary($request, $weather->summary, $cost, $fallbackSummary, $transportRecommendations, $hotelRecommendations, $attractionRecommendations);

        return new TravelPlan(
            destination: $destination,
            origin: $origin,
            days: $request->days,
            weatherSummary: $weather->summary,
            weatherExtra: $weather->extra,
            transportOptions: $transportRecommendations,
            hotels: $hotelRecommendations,
            attractions: $attractionRecommendations,
            dailyItinerary: $optimized['daily_itinerary'],
            estimatedCost: $cost,
            finalRecommendation: $summary,
            providerStatus: [
                'weather' => $this->status($weather),
                'transport' => $this->status($transport),
                'hotels' => $this->status($hotels),
                'attractions' => $this->status($attractions),
                'advisor' => [
                    'status' => 'ok',
                    'source' => 'Laravel TravelAdvisor policy',
                    'notes' => [$advisor['status']['selection_method']],
                    'count' => count($advisor['status']['agent_reviews']),
                    ...$advisor['status'],
                ],
                'cost' => [
                    'status' => 'ok',
                    'source' => 'Laravel calculated trip allowance',
                    'notes' => $breakdown['notes'] ?? [],
                    'count' => count(array_filter($breakdown, fn ($value): bool => is_int($value) && $value > 0)),
                    'breakdown' => $breakdown,
                ],
                'itinerary_optimizer' => [
                    'status' => 'ok',
                    'source' => 'Laravel ItineraryOptimizerAgent',
                    'notes' => $optimized['decisions'],
                    'count' => count($optimized['daily_itinerary']),
                    'score' => $optimized['optimization_score'],
                    'issues' => $optimized['issues'],
                    'budget_breakdown' => $breakdown,
                    'revision_count' => $optimized['revision_count'],
                ],
                'debug' => [
                    'request' => $request->toArray(),
                    'resolved_origin' => $this->locations->resolve($request->origin),
                    'resolved_destination' => $this->locations->resolve($request->destination),
                    'orchestration' => $orchestration,
                    'transport_count' => count($transportRecommendations),
                    'transport_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($transportRecommendations, 0, 5)),
                    'transport_notes' => $transport->notes,
                    'hotel_count' => count($hotelRecommendations),
                    'hotel_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($hotelRecommendations, 0, 5)),
                    'attraction_count' => count($attractionRecommendations),
                    'attraction_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($attractionRecommendations, 0, 5)),
                    'attraction_debug' => $attractions->extra['debug'] ?? [],
                ],
            ],
        );
    }

    /**
     * Mirrors Python's RootTravelPlannerAgent ThreadPoolExecutor phase:
     * weather, transport, and hotels are independent and can run concurrently.
     */
    private function runCoreAgents(UserRequest $request): array
    {
        $driver = app()->environment('testing') ? 'sync' : (env('TRAVEL_PLANNER_CONCURRENCY_DRIVER') ?: 'process');
        try {
            $started = microtime(true);
            $results = Concurrency::driver($driver)->run([
                'weather' => fn () => app(WeatherAgent::class)->run($request),
                'transport' => fn () => app(TransportAgent::class)->run($request),
                'hotels' => fn () => app(HotelAgent::class)->run($request),
            ]);
            $weather = $results['weather'];
            $transport = $results['transport'];
            $hotels = $results['hotels'];
            $partialFallback = [];
            if ($driver !== 'sync' && $weather instanceof AgentResult && $this->shouldRetryWeatherSynchronously($weather)) {
                $weather = $this->weatherAgent->run($request);
                $partialFallback[] = 'weather-agent';
            }
            if ($driver !== 'sync' && $transport instanceof AgentResult && $this->shouldRetryTransportSynchronously($transport)) {
                $transport = $this->transportAgent->run($request);
                $partialFallback[] = 'transport-agent';
            }
            if ($driver !== 'sync' && $hotels instanceof AgentResult && $this->shouldRetryHotelsSynchronously($hotels)) {
                $hotels = $this->hotelAgent->run($request);
                $partialFallback[] = 'hotel-agent';
            }

            return [
                $weather,
                $transport,
                $hotels,
                [
                    'mode' => $driver === 'sync' ? 'sync' : 'parallel',
                    'driver' => $driver,
                    'agents' => ['weather-agent', 'transport-agent', 'hotel-agent'],
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'fallback' => $partialFallback !== [],
                    'partial_fallback' => $partialFallback,
                ],
            ];
        } catch (Throwable $exception) {
            $started = microtime(true);
            $weather = $this->weatherAgent->run($request);
            $transport = $this->transportAgent->run($request);
            $hotels = $this->hotelAgent->run($request);

            return [
                $weather,
                $transport,
                $hotels,
                [
                    'mode' => 'sync',
                    'driver' => $driver,
                    'agents' => ['weather-agent', 'transport-agent', 'hotel-agent'],
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'fallback' => true,
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function shouldRetryWeatherSynchronously(AgentResult $weather): bool
    {
        if ($weather->status !== 'empty') {
            return false;
        }

        $notes = implode(' ', $weather->notes);

        return str_contains($notes, 'cURL error 6')
            || str_contains($notes, 'getaddrinfo() thread failed to start');
    }

    private function shouldRetryTransportSynchronously(AgentResult $transport): bool
    {
        if ($transport->status !== 'ok' || $transport->recommendations === []) {
            return false;
        }

        $payload = strtolower(json_encode(array_map(fn (Recommendation $item): array => $item->toArray(), $transport->recommendations), JSON_UNESCAPED_UNICODE) ?: '');

        return str_contains($payload, 'estimated adapter')
            || str_contains($payload, 'local fallback dataset');
    }

    private function shouldRetryHotelsSynchronously(AgentResult $hotels): bool
    {
        if ($hotels->status === 'empty') {
            return true;
        }

        $source = strtolower($hotels->source);
        $notes = strtolower(implode(' ', $hotels->notes));

        return str_contains($source, 'local fallback')
            || str_contains($notes, 'local fallback active')
            || str_contains($notes, 'live hotel provider unavailable');
    }

    /**
     * @param array<int, Recommendation> $items
     * @return array<int, Recommendation>
     */
    private function markPrimary(array $items): array
    {
        foreach ($items as $index => $item) {
            $item->title = preg_replace('/^\[Đề xuất chính\]\s*/u', '', $item->title) ?? $item->title;
            if ($index === 0) {
                $item->title = '[Đề xuất chính] '.$item->title;
            }
        }

        return $items;
    }

    private function hotelContext(AgentResult $hotels): array
    {
        $rawHotels = $hotels->extra['hotel_candidates'] ?? [];
        $topRaw = is_array($rawHotels) ? ($rawHotels[0] ?? []) : [];
        $top = $hotels->recommendations[0] ?? null;
        if (! $topRaw && ! $top) {
            return ['confidence' => 'none'];
        }
        $area = (string) ($topRaw['area'] ?? '');
        if ($area === '' && $top && preg_match('/(?:Area|Khu vực):\s*([^|]+)/u', $top->details, $match)) {
            $area = trim($match[1]);
        }

        return [
            'name' => $topRaw['name'] ?? ($top?->title ?? ''),
            'area' => $area,
            'lat' => $topRaw['lat'] ?? null,
            'lon' => $topRaw['lon'] ?? null,
            'source' => $topRaw['source'] ?? $hotels->source,
            'confidence' => isset($topRaw['lat'], $topRaw['lon']) ? 'high' : 'medium',
        ];
    }

    private function status(AgentResult $result): array
    {
        return [
            'status' => $result->status,
            'source' => $result->source,
            'notes' => $result->notes,
            'count' => count($result->recommendations),
        ];
    }

    private function summary(UserRequest $request, string $origin, string $destination, AgentResult $weather, array $transport, array $hotels, array $attractions, int $cost, array $breakdown): string
    {
        $isVi = strtolower($request->lang ?: 'vi') === 'vi';
        $lines = [
            $isVi
                ? "Tuyến đi: {$origin} đến {$destination} trong {$request->days} ngày."
                : "Route: {$origin} to {$destination} for {$request->days} days.",
            $isVi ? $weather->summary : 'Weather outlook: '.$weather->summary,
        ];

        if ($isVi) {
            $lines[] = $transport ? 'Di chuyển gợi ý: '.$transport[0]->title.' ('.number_format($transport[0]->price, 0, ',', '.').' VND)' : 'Chưa có gợi ý di chuyển phù hợp.';
            $lines[] = $hotels ? 'Lưu trú gợi ý: '.$hotels[0]->title.' ('.number_format($hotels[0]->price, 0, ',', '.').' VND tổng lưu trú)' : 'Chưa có gợi ý lưu trú phù hợp.';
            $lines[] = $attractions ? 'Điểm tham quan nổi bật: '.implode(', ', array_map(fn (Recommendation $item): string => $item->title, array_slice($attractions, 0, 3))) : 'Chưa có gợi ý điểm tham quan phù hợp.';
            $lines[] = $cost > $request->budget ? 'Chi phí ước tính đang vượt ngân sách, nên cân nhắc phương án tiết kiệm hơn.' : 'Chi phí ước tính vẫn nằm trong ngân sách.';
            $lines[] = sprintf(
                'Cơ cấu chi phí: di chuyển %s VND, lưu trú %s VND, tham quan %s VND, ăn uống/nội đô %s VND, nâng trải nghiệm %s VND, dự phòng %s VND.',
                number_format($breakdown['transport'] ?? 0, 0, ',', '.'),
                number_format($breakdown['lodging'] ?? 0, 0, ',', '.'),
                number_format($breakdown['attractions'] ?? 0, 0, ',', '.'),
                number_format($breakdown['daily_allowance'] ?? 0, 0, ',', '.'),
                number_format($breakdown['experience_adjustment'] ?? 0, 0, ',', '.'),
                number_format($breakdown['contingency'] ?? 0, 0, ',', '.'),
            );
        } else {
            $lines[] = $transport ? 'Suggested transport: '.$transport[0]->title.' ('.number_format($transport[0]->price, 0, ',', '.').' VND)' : 'Transport suggestions are currently unavailable from live providers.';
            $lines[] = $hotels ? 'Suggested hotel: '.$hotels[0]->title.' ('.number_format($hotels[0]->price, 0, ',', '.').' VND total)' : 'Hotel suggestions are currently unavailable from live providers.';
            $lines[] = $attractions ? 'Recommended attractions: '.implode(', ', array_map(fn (Recommendation $item): string => $item->title, array_slice($attractions, 0, 3))) : 'Attraction suggestions are currently unavailable from live providers.';
            $lines[] = $cost > $request->budget ? 'Estimated cost is above your budget, so lower-cost options should be considered.' : 'Estimated cost is still within budget.';
            $lines[] = sprintf(
                'Cost breakdown: transport %s VND, lodging %s VND, attractions %s VND, meals/local travel %s VND, experience adjustment %s VND, contingency %s VND.',
                number_format($breakdown['transport'] ?? 0, 0, ',', '.'),
                number_format($breakdown['lodging'] ?? 0, 0, ',', '.'),
                number_format($breakdown['attractions'] ?? 0, 0, ',', '.'),
                number_format($breakdown['daily_allowance'] ?? 0, 0, ',', '.'),
                number_format($breakdown['experience_adjustment'] ?? 0, 0, ',', '.'),
                number_format($breakdown['contingency'] ?? 0, 0, ',', '.'),
            );
        }

        return implode("\n", $lines);
    }

    private function buildFinalSummary(UserRequest $request, string $weatherSummary, int $estimatedCost, string $fallbackSummary, array $transport, array $hotels, array $attractions): string
    {
        if (strtolower($request->lang ?: 'vi') === 'vi') {
            return $fallbackSummary;
        }

        $apiKey = env('OPENAI_API_KEY');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $fallbackSummary;
        }

        $payload = [
            'request' => $request->toArray(),
            'weather_summary' => $weatherSummary,
            'estimated_cost' => $estimatedCost,
            'transport' => array_map(fn (Recommendation $item): array => $item->toArray(), array_slice($transport, 0, 2)),
            'hotels' => array_map(fn (Recommendation $item): array => $item->toArray(), array_slice($hotels, 0, 2)),
            'attractions' => array_map(fn (Recommendation $item): array => $item->toArray(), array_slice($attractions, 0, 3)),
        ];

        $prompt = "Write a concise premium travel planner summary in English. "
            ."Keep it to 5-7 short lines, plain text only. "
            ."Mention destination, weather, top transport option, top stay, top attractions, "
            ."and budget fit in a user-friendly style.\n\n"
            .'Data:'."\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $data = Http::timeout(20)
                ->withToken(trim($apiKey))
                ->post(env('OPENAI_CHAT_COMPLETIONS_URL', 'https://api.openai.com/v1/chat/completions'), [
                    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a polished AI travel planner assistant.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.3,
                ])
                ->throw()
                ->json() ?: [];

            $text = trim((string) data_get($data, 'choices.0.message.content', ''));
            return $text !== '' ? $text : $fallbackSummary;
        } catch (Throwable) {
            return $fallbackSummary;
        }
    }
}
