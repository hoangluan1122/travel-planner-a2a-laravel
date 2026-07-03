<?php

namespace App\Http\Controllers;

use App\TravelPlanner\Agents\WeatherAgent;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\ImageContext;
use App\TravelPlanner\Services\LiveTravelService;
use App\TravelPlanner\Services\RequestParser;
use App\TravelPlanner\Services\RootTravelPlanner;
use App\TravelPlanner\Services\TravelDataRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class TravelPlannerController extends Controller
{
    public function __construct(
        private readonly RequestParser $parser,
        private readonly RootTravelPlanner $planner,
        private readonly ImageContext $images,
        private readonly TravelDataRepository $data,
        private readonly LiveTravelService $live,
        private readonly WeatherAgent $weatherAgent,
    ) {
    }

    public function home(Request $request): View
    {
        return view('travel.index', $this->viewContext(lang: $this->normalizeLang($request->query('lang', 'vi'))));
    }

    public function legacyPlan(Request $request): View
    {
        $plan = $this->buildPlan($request);
        return view('travel.index', $this->viewContext($plan, $request->input('user_text', ''), $request->input('origin', ''), $request->input('departure_date', ''), $request->input('lang', 'vi')));
    }

    public function store(Request $request): RedirectResponse|Response
    {
        try {
            $plan = $this->buildPlan($request);
            $planId = Str::uuid()->toString();
            Cache::put($this->cacheKey($planId), [
                'plan' => $plan->toArray(),
                'user_text' => $request->input('user_text', ''),
                'origin' => $request->input('origin', ''),
                'departure_date' => $request->input('departure_date') ?: '',
                'lang' => $request->input('lang') ?: 'vi',
            ], now()->addHour());

            return redirect()->route('travel.v2.result', ['planId' => $planId], 303);
        } catch (\Throwable $exception) {
            return response(view('travel.index', $this->viewContext(error: $exception->getMessage(), lang: $this->normalizeLang($request->input('lang', 'vi')))), 500);
        }
    }

    public function result(string $planId): View
    {
        $cached = Cache::get($this->cacheKey($planId));
        if (! $cached) {
            return view('travel.index', $this->viewContext(error: 'Khong tim thay ke hoach nay hoac ke hoach da het han. Hay tao lai hanh trinh.'));
        }
        if ($this->shouldRefreshCachedPlan($cached)) {
            try {
                $text = (string) ($cached['user_text'] ?? '');
                if (($cached['departure_date'] ?? '') !== '') {
                    $text .= ' Departure '.$cached['departure_date'];
                }
                $parsed = $this->parser->parse($text, (string) ($cached['origin'] ?? ''));
                $parsed->lang = $this->normalizeLang((string) ($cached['lang'] ?? $parsed->lang));
                $cached['plan'] = $this->planner->run($parsed)->toArray();
                Cache::put($this->cacheKey($planId), $cached, now()->addHour());
            } catch (\Throwable) {
                // Keep the existing cached plan if a refresh attempt fails.
            }
        }

        return view('travel.index', array_merge(
            $this->viewContext(
                planArray: $cached['plan'] ?? null,
                userText: (string) ($cached['user_text'] ?? ''),
                origin: (string) ($cached['origin'] ?? ''),
                departureDate: (string) ($cached['departure_date'] ?? ''),
                lang: (string) ($cached['lang'] ?? 'vi'),
            ),
            ['plan_id' => $planId],
        ));
    }

    public function apiPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_text' => ['required', 'string'],
            'origin' => ['nullable', 'string'],
            'lang' => ['nullable', 'string'],
            'departure_date' => ['nullable', 'string'],
            'budget_millions' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'budget' => ['nullable', 'integer', 'min:1'],
        ]);
        $text = $validated['user_text'].(! empty($validated['departure_date']) ? ' Departure '.$validated['departure_date'] : '');
        $parsed = $this->parser->parse($text, $validated['origin'] ?? null);
        $this->applyExplicitBudget($parsed, $request);
        if (! empty($validated['lang'])) {
            $parsed->lang = $this->normalizeLang($validated['lang']);
        }
        $plan = $this->planner->run($parsed);

        return response()->json([
            'parsed_request' => $parsed->toArray(),
            'travel_plan' => $plan->toArray(),
            'images' => $this->images->build($plan),
        ]);
    }

    public function debugPlan(Request $request): JsonResponse
    {
        $parsed = $this->parser->parse((string) $request->query('user_text', ''), $request->query('origin'));
        $plan = $this->planner->run($parsed);
        $payload = $plan->toArray();

        return response()->json([
            'parsed_request' => $parsed->toArray(),
            'estimated_cost' => $payload['estimated_cost'],
            'cost_breakdown' => $payload['provider_status']['cost']['breakdown'] ?? [],
            'provider_status' => $payload['provider_status'],
            'transport_titles' => array_column($payload['transport_options'], 'title'),
            'hotel_titles' => array_column($payload['hotels'], 'title'),
            'attraction_titles' => array_column($payload['attractions'], 'title'),
        ]);
    }

    public function providersStatus(Request $request): JsonResponse
    {
        $destination = (string) $request->query('destination', 'Da Nang');
        $origin = (string) $request->query('origin', 'Ha Noi');
        $departureDate = (string) $request->query('departure_date', '');
        $travelers = (int) $request->query('travelers', 2);
        $budget = (int) $request->query('budget', 8000000);
        $weather = $this->weatherAgent->run(new UserRequest(
            destination: $destination,
            origin: $origin,
            departureDate: $departureDate,
            days: (int) $request->query('days', 3),
            budget: $budget,
            travelers: max($travelers, 1),
            adults: max($travelers, 1),
            lang: (string) $request->query('lang', 'vi'),
        ));
        $hotels = $this->live->fetchLiveHotels($destination);
        $attractions = $this->live->fetchLiveAttractions($destination);
        [$flights, $flightDebug] = $this->live->fetchLiveFlightsWithDebug(
            destination: $destination,
            adults: $travelers,
            maxPrice: $budget,
            origin: $origin,
            departureDate: $departureDate,
        );
        $fallbackFlights = [];
        if ($hotels === []) {
            $hotels = $this->data->hotels($destination);
        }
        if ($attractions === []) {
            $attractions = $this->data->attractions($destination);
        }
        if ($flights === []) {
            $fallbackFlights = $this->data->flights($destination);
        }

        return response()->json([
            'destination' => $destination,
            'origin' => $origin,
            'weather_key_present' => (bool) env('OPENWEATHER_API_KEY'),
            'serpapi_key_present' => (bool) env('SERPAPI_KEY'),
            'origin_iata_present' => (bool) env('ORIGIN_IATA'),
            'geoapify_key_present' => (bool) env('GEOAPIFY_API_KEY'),
            'weather_status' => $weather->status,
            'weather_summary' => $weather->summary,
            'weather_forecast_count' => count($weather->extra['forecast'] ?? []),
            'weather_forecast_preview' => array_slice($weather->extra['forecast'] ?? [], 0, 3),
            'hotels_count' => count($hotels),
            'attractions_count' => count($attractions),
            'flights_count' => count($flights),
            'live_flights_count' => count($flights),
            'fallback_flights_count' => count($fallbackFlights),
            'hotels_preview' => array_slice($hotels, 0, 2),
            'attractions_preview' => array_slice($attractions, 0, 2),
            'flights_preview' => array_slice($flights, 0, 2),
            'fallback_flights_preview' => array_slice($fallbackFlights, 0, 2),
            'flight_debug' => $flightDebug,
        ]);
    }

    public function reverseOrigin(Request $request): JsonResponse
    {
        return response()->json($this->live->reverseGeocodeToOrigin(
            lat: (float) $request->query('lat'),
            lon: (float) $request->query('lon'),
        ));
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'openweather' => (bool) env('OPENWEATHER_API_KEY'),
            'serpapi' => (bool) env('SERPAPI_KEY'),
            'origin_iata' => (bool) env('ORIGIN_IATA'),
            'framework' => 'laravel',
        ]);
    }

    private function buildPlan(Request $request)
    {
        $request->validate([
            'user_text' => ['required', 'string'],
            'origin' => ['nullable', 'string'],
            'departure_date' => ['nullable', 'string'],
            'lang' => ['nullable', 'string'],
            'budget_millions' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'budget' => ['nullable', 'integer', 'min:1'],
        ]);
        $text = $request->input('user_text');
        if ($request->filled('departure_date')) {
            $text .= ' Departure '.$request->input('departure_date');
        }
        $parsed = $this->parser->parse($text, $request->input('origin'));
        $this->applyExplicitBudget($parsed, $request);
        $parsed->lang = $this->normalizeLang($request->input('lang', $parsed->lang));

        return $this->planner->run($parsed);
    }

    private function applyExplicitBudget(UserRequest $parsed, Request $request): void
    {
        if ($request->filled('budget_millions')) {
            $parsed->budget = max(1, (int) round((float) $request->input('budget_millions') * 1000000));

            return;
        }

        if ($request->filled('budget')) {
            $budget = (int) $request->input('budget');
            $parsed->budget = $budget < 1000 ? $budget * 1000000 : $budget;
        }
    }

    private function viewContext($plan = null, ?string $userText = '', ?string $origin = '', ?string $departureDate = '', ?string $lang = 'vi', ?string $error = null, ?array $planArray = null): array
    {
        $planArray ??= $plan?->toArray();
        return [
            'result' => $planArray,
            'parsed' => $planArray['provider_status']['debug']['request'] ?? null,
            'user_text' => $userText ?? '',
            'origin_value' => $origin ?? '',
            'departure_date' => $departureDate ?? '',
            'error' => $error,
            'provider_status' => $planArray['provider_status'] ?? null,
            'lang' => $this->normalizeLang($lang),
            ...$this->images->build($plan),
        ];
    }

    private function cacheKey(string $planId): string
    {
        return 'travel_planner_v2_'.$planId;
    }

    private function shouldRefreshCachedPlan(array $cached): bool
    {
        $plan = $cached['plan'] ?? null;
        if (! is_array($plan) || trim((string) ($cached['user_text'] ?? '')) === '') {
            return false;
        }

        $weatherCanRefresh = (bool) env('OPENWEATHER_API_KEY')
            && data_get($plan, 'provider_status.weather.status') === 'empty';
        $transportText = strtolower(json_encode(data_get($plan, 'transport_options', []), JSON_UNESCAPED_UNICODE) ?: '');
        $transportCanRefresh = str_contains($transportText, 'estimated adapter')
            || str_contains($transportText, 'local fallback dataset');

        return $weatherCanRefresh || $transportCanRefresh;
    }

    private function normalizeLang(?string $lang): string
    {
        return str_starts_with(strtolower((string) $lang), 'en') ? 'en' : 'vi';
    }
}
