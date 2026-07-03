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

final class RootTravelPlanner
{
    public function __construct(
        private readonly WeatherAgent $weatherAgent,
        private readonly TransportAgent $transportAgent,
        private readonly HotelAgent $hotelAgent,
        private readonly AttractionAgent $attractionAgent,
        private readonly ItineraryOptimizerAgent $itineraryOptimizer,
        private readonly LocationResolver $locations,
    ) {
    }

    public function run(UserRequest $request): TravelPlan
    {
        $weather = $this->weatherAgent->run($request);
        $transport = $this->transportAgent->run($request);
        $hotels = $this->hotelAgent->run($request);
        $attractions = $this->attractionAgent->run($request, $weather, $this->hotelContext($hotels));
        $optimized = $this->itineraryOptimizer->run($request, $weather->summary, $transport->recommendations, $hotels->recommendations, $attractions->recommendations);

        $origin = $this->locations->resolve($request->origin)['canonical_name'];
        $destination = $this->locations->resolve($request->destination)['canonical_name'];
        $cost = $optimized['total_cost'];
        $breakdown = $optimized['budget_breakdown'];
        $summary = $this->summary($request, $origin, $destination, $weather, $transport->recommendations, $hotels->recommendations, $attractions->recommendations, $cost, $breakdown);

        return new TravelPlan(
            destination: $destination,
            origin: $origin,
            days: $request->days,
            weatherSummary: $weather->summary,
            weatherExtra: $weather->extra,
            transportOptions: $transport->recommendations,
            hotels: $hotels->recommendations,
            attractions: $attractions->recommendations,
            dailyItinerary: $optimized['daily_itinerary'],
            estimatedCost: $cost,
            finalRecommendation: $summary,
            providerStatus: [
                'weather' => $this->status($weather),
                'transport' => $this->status($transport),
                'hotels' => $this->status($hotels),
                'attractions' => $this->status($attractions),
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
                    'transport_count' => count($transport->recommendations),
                    'hotel_count' => count($hotels->recommendations),
                    'attraction_count' => count($attractions->recommendations),
                ],
            ],
        );
    }

    private function hotelContext(AgentResult $hotels): array
    {
        $top = $hotels->recommendations[0] ?? null;
        if (! $top) {
            return ['confidence' => 'none'];
        }
        $area = '';
        if (preg_match('/Area:\s*([^|]+)/', $top->details, $match)) {
            $area = trim($match[1]);
        }
        return ['name' => $top->title, 'area' => $area, 'source' => $hotels->source, 'confidence' => 'medium'];
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
        $lines = [
            "Tuyen di: {$origin} den {$destination} trong {$request->days} ngay.",
            $weather->summary,
        ];

        $lines[] = $transport ? 'Di chuyen goi y: '.$transport[0]->title.' ('.number_format($transport[0]->price, 0, ',', '.').' VND)' : 'Chua co goi y di chuyen phu hop.';
        $lines[] = $hotels ? 'Luu tru goi y: '.$hotels[0]->title.' ('.number_format($hotels[0]->price, 0, ',', '.').' VND tong luu tru)' : 'Chua co goi y luu tru phu hop.';
        $lines[] = $attractions ? 'Diem tham quan noi bat: '.implode(', ', array_map(fn (Recommendation $item): string => $item->title, array_slice($attractions, 0, 3))) : 'Chua co goi y diem tham quan phu hop.';
        $lines[] = $cost > $request->budget ? 'Chi phi uoc tinh dang vuot ngan sach, nen can can nhac phuong an tiet kiem hon.' : 'Chi phi uoc tinh van nam trong ngan sach.';
        $lines[] = sprintf(
            'Co cau chi phi: di chuyen %s VND, luu tru %s VND, tham quan %s VND, an uong/noi do %s VND, nang trai nghiem %s VND, du phong %s VND.',
            number_format($breakdown['transport'] ?? 0, 0, ',', '.'),
            number_format($breakdown['lodging'] ?? 0, 0, ',', '.'),
            number_format($breakdown['attractions'] ?? 0, 0, ',', '.'),
            number_format($breakdown['daily_allowance'] ?? 0, 0, ',', '.'),
            number_format($breakdown['experience_adjustment'] ?? 0, 0, ',', '.'),
            number_format($breakdown['contingency'] ?? 0, 0, ',', '.'),
        );

        return implode("\n", $lines);
    }
}
