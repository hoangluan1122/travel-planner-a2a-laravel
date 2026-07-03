<?php

namespace App\TravelPlanner\Services;

use Illuminate\Support\Facades\Http;

final class LiveTravelService
{
    private const SERPAPI_URL = 'https://serpapi.com/search.json';
    private const GEOAPIFY_PLACES_URL = 'https://api.geoapify.com/v2/places';
    private const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';
    private const NOMINATIM_REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';

    private const CITY_AIRPORT_CODES = [
        'Da Nang' => 'DAD',
        'Da Lat' => 'DLI',
        'Ha Noi' => 'HAN',
        'Lao Cai' => 'HAN',
        'Sa Pa' => 'HAN',
        'Ho Chi Minh' => 'SGN',
        'Nha Trang' => 'CXR',
        'Hue' => 'HUI',
        'Hoi An' => 'DAD',
        'Can Tho' => 'VCA',
        'Vung Tau' => 'SGN',
        'Ninh Binh' => 'HAN',
        'Phu Quoc' => 'PQC',
        'Con Dao' => 'VCS',
        'Quy Nhon' => 'UIH',
        'Mang Den' => 'PXU',
        'Buon Ma Thuot' => 'BMV',
        'Pleiku' => 'PXU',
    ];

    public function __construct(private readonly LocationResolver $locations)
    {
    }

    public function fetchLiveFlights(string $destination, int $adults = 1, ?int $maxPrice = null, ?string $origin = null, ?string $departureDate = null): array
    {
        [$flights] = $this->fetchLiveFlightsWithDebug($destination, $adults, $maxPrice, $origin, $departureDate);
        return $flights;
    }

    public function fetchLiveFlightsWithDebug(string $destination, int $adults = 1, ?int $maxPrice = null, ?string $origin = null, ?string $departureDate = null): array
    {
        $debug = [
            'provider' => 'SerpAPI Google Flights',
            'destination' => $destination,
            'origin_input' => $origin,
            'origin_resolved' => null,
            'departure_date' => null,
            'params_preview' => null,
            'http_status' => null,
            'error' => null,
            'message' => null,
            'raw_keys' => [],
            'raw_preview' => null,
        ];

        $apiKey = $this->secret('SERPAPI_KEY');
        if (! $apiKey) {
            $debug['error'] = 'SERPAPI_KEY missing';
            return [[], $debug];
        }

        $originResolved = $this->locations->resolve($origin ?: '');
        $destinationResolved = $this->locations->resolve($destination);
        $originCode = strtoupper((string) ($originResolved['iata'] ?? $this->secret('ORIGIN_IATA') ?? 'SGN'));
        $destinationCode = $destinationResolved['iata'] ?? self::CITY_AIRPORT_CODES[$destinationResolved['canonical_name']] ?? null;
        $debug['origin_resolved'] = $originCode;
        if (! $destinationCode) {
            $debug['error'] = 'No airport mapping for destination: '.$destination;
            return [[], $debug];
        }

        $outboundDate = $departureDate ?: now()->addDays(14)->toDateString();
        $debug['departure_date'] = $outboundDate;
        $params = [
            'engine' => 'google_flights',
            'type' => '2',
            'departure_id' => $originCode,
            'arrival_id' => $destinationCode,
            'outbound_date' => $outboundDate,
            'currency' => 'VND',
            'hl' => 'en',
            'adults' => max(1, $adults),
            'api_key' => $apiKey,
        ];
        $debug['params_preview'] = [...$params, 'api_key' => '***'];

        try {
            $response = Http::timeout(18)->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])->get(self::SERPAPI_URL, $params);
            $debug['http_status'] = $response->status();
            $data = $response->json() ?: [];
            $debug['raw_keys'] = array_keys($data);
            $debug['raw_preview'] = [
                'search_metadata_status' => data_get($data, 'search_metadata.status'),
                'search_information' => $data['search_information'] ?? null,
                'error' => $data['error'] ?? null,
            ];
            if ($response->failed()) {
                $debug['error'] = $data['error'] ?? 'HTTP '.$response->status();
                return [[], $debug];
            }

            $flights = [];
            foreach (['best_flights', 'other_flights'] as $bucket) {
                foreach (($data[$bucket] ?? []) as $item) {
                    $segments = $item['flights'] ?? [];
                    if ($segments === []) {
                        continue;
                    }
                    $first = $segments[0];
                    $last = $segments[count($segments) - 1];
                    $price = (int) ($item['price'] ?? 0);
                    if ($maxPrice && $price && $price > $maxPrice) {
                        continue;
                    }
                    $flights[] = [
                        'airline' => $first['airline'] ?? 'Flight',
                        'departure' => data_get($first, 'departure_airport.id', $originCode),
                        'arrival' => data_get($last, 'arrival_airport.id', $destinationCode),
                        'departure_time' => data_get($first, 'departure_airport.time', ''),
                        'arrival_time' => data_get($last, 'arrival_airport.time', ''),
                        'duration_minutes' => (int) ($item['total_duration'] ?? 0),
                        'stops' => max(count($segments) - 1, 0),
                        'price' => $price,
                        'source' => 'SerpAPI Google Flights',
                    ];
                }
            }

            $flights = array_slice($this->dedupeBy($flights, ['airline', 'departure', 'arrival', 'departure_time', 'arrival_time', 'price']), 0, 8);
            $debug['message'] = 'Returned '.count($flights).' flights';
            return [$flights, $debug];
        } catch (\Throwable $exception) {
            $debug['error'] = $exception->getMessage();
            return [[], $debug];
        }
    }

    public function fetchLiveHotels(string $destination, int $limit = 8): array
    {
        $features = $this->geoapifyPlaces('accommodation.hotel,accommodation.guest_house,accommodation.hostel,accommodation.motel', $destination, 12000, $limit);
        $results = [];
        foreach ($features as $feature) {
            $props = $feature['properties'] ?? [];
            $name = $props['name'] ?? $props['formatted'] ?? null;
            if (! $name) {
                continue;
            }
            $results[] = [
                'name' => $name,
                'area' => $props['suburb'] ?? $props['district'] ?? $props['city'] ?? $destination,
                'rating' => 4.0,
                'review_count' => 0,
                'price_per_night' => 800000,
                'amenities' => ['wifi', 'breakfast'],
                'source' => 'Geoapify',
                'lat' => data_get($feature, 'geometry.coordinates.1'),
                'lon' => data_get($feature, 'geometry.coordinates.0'),
            ];
        }
        return $results;
    }

    public function fetchLiveAttractions(string $destination, int $limit = 10): array
    {
        $features = $this->geoapifyPlaces('tourism.sights,entertainment,museum,leisure.park,natural', $destination, 18000, $limit);
        $results = [];
        foreach ($features as $feature) {
            $props = $feature['properties'] ?? [];
            $name = $props['name'] ?? $props['formatted'] ?? null;
            if (! $name) {
                continue;
            }
            $categoryText = strtolower(implode(' ', $props['categories'] ?? []));
            $tags = ['explore'];
            $type = str_contains($categoryText, 'museum') ? 'indoor' : 'outdoor';
            $cost = 0;
            if (str_contains($categoryText, 'museum')) {
                array_push($tags, 'history', 'culture');
                $cost = 100000;
            }
            if (str_contains($categoryText, 'park') || str_contains($categoryText, 'natural')) {
                array_push($tags, 'nature', 'photo');
            }
            if (str_contains($categoryText, 'tourism') || str_contains($categoryText, 'sights')) {
                $tags[] = 'photo';
            }
            $results[] = [
                'name' => $name,
                'type' => $type,
                'interest_tags' => array_values(array_unique($tags)),
                'cost' => $cost,
                'source' => 'Geoapify',
                'area' => $props['suburb'] ?? $props['district'] ?? $props['city'] ?? $destination,
                'lat' => data_get($feature, 'geometry.coordinates.1'),
                'lon' => data_get($feature, 'geometry.coordinates.0'),
            ];
        }
        return $results;
    }

    public function reverseGeocodeToOrigin(float $lat, float $lon): array
    {
        try {
            $data = Http::timeout(15)->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])->get(self::NOMINATIM_REVERSE_URL, [
                'lat' => $lat,
                'lon' => $lon,
                'format' => 'jsonv2',
                'zoom' => 10,
                'addressdetails' => 1,
            ])->throw()->json() ?: [];

            $address = $data['address'] ?? [];
            foreach (['city', 'municipality', 'state', 'province', 'town', 'county', 'region'] as $key) {
                if (empty($address[$key])) {
                    continue;
                }
                $resolved = $this->locations->resolve($address[$key]);
                if (! empty($resolved['iata'])) {
                    return ['origin_label' => $resolved['canonical_name'], 'origin_iata' => $resolved['iata']];
                }
            }
        } catch (\Throwable) {
            //
        }

        return ['origin_label' => 'Unknown location', 'origin_iata' => $this->secret('ORIGIN_IATA') ?: 'SGN'];
    }

    public function geocodeDestination(string $destination): ?array
    {
        $resolved = $this->locations->resolve($destination);
        if ($resolved['lat'] !== null && $resolved['lon'] !== null) {
            return [(float) $resolved['lat'], (float) $resolved['lon']];
        }

        try {
            $rows = Http::timeout(15)->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])->get(self::NOMINATIM_SEARCH_URL, [
                'q' => $destination,
                'format' => 'jsonv2',
                'limit' => 1,
                'countrycodes' => 'vn',
            ])->throw()->json() ?: [];
            if ($rows !== []) {
                return [(float) $rows[0]['lat'], (float) $rows[0]['lon']];
            }
        } catch (\Throwable) {
            //
        }

        return null;
    }

    private function geoapifyPlaces(string $categories, string $destination, int $radius, int $limit): array
    {
        $apiKey = $this->secret('GEOAPIFY_API_KEY');
        $coords = $this->geocodeDestination($destination);
        if (! $apiKey || ! $coords) {
            return [];
        }
        [$lat, $lon] = $coords;

        try {
            $data = Http::timeout(12)->get(self::GEOAPIFY_PLACES_URL, [
                'categories' => $categories,
                'filter' => "circle:{$lon},{$lat},{$radius}",
                'bias' => "proximity:{$lon},{$lat}",
                'limit' => $limit,
                'lang' => 'vi',
                'apiKey' => $apiKey,
            ])->throw()->json() ?: [];
            return $data['features'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function secret(string $name): ?string
    {
        $value = env($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value, " \t\n\r\0\x0B\"'");
    }

    private function dedupeBy(array $rows, array $keys): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = implode('|', array_map(fn (string $name): string => (string) ($row[$name] ?? ''), $keys));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }
}
