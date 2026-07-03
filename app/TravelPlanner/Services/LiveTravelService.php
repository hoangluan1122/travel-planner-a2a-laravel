<?php

namespace App\TravelPlanner\Services;

use App\Support\Text;
use Illuminate\Support\Facades\Http;

final class LiveTravelService
{
    private const SERPAPI_URL = 'https://serpapi.com/search.json';
    private const GEOAPIFY_PLACES_URL = 'https://api.geoapify.com/v2/places';
    private const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';
    private const NOMINATIM_REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';
    private const RAPIDAPI_BASE_URL = 'https://booking-com15.p.rapidapi.com/api/v1';
    private const WIKIPEDIA_URLS = [
        'vi' => 'https://vi.wikipedia.org/w/api.php',
        'en' => 'https://en.wikipedia.org/w/api.php',
    ];
    private const OVERPASS_URLS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.openstreetmap.ru/api/interpreter',
    ];

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

    private const CURATED_ACTIVITY_ATTRACTIONS = [
        'da nang' => [
            [
                'name' => 'My Khe Beach',
                'type' => 'outdoor',
                'interest_tags' => ['beach', 'swimming', 'photo', 'relax', 'nature'],
                'cost' => 0,
                'source' => 'Curated Da Nang attractions',
                'area' => 'My Khe',
                'lat' => 16.0617,
                'lon' => 108.2468,
                'suitability' => 'Bai bien cong cong rong, phu hop tam bien, di dao binh minh va ghe quan ca phe gan bien.',
            ],
            [
                'name' => 'Non Nuoc Beach',
                'type' => 'outdoor',
                'interest_tags' => ['beach', 'swimming', 'resort', 'photo', 'relax'],
                'cost' => 0,
                'source' => 'Curated Da Nang attractions',
                'area' => 'Ngu Hanh Son',
                'lat' => 16.0008,
                'lon' => 108.2682,
                'suitability' => 'Bai bien dai gan cum resort va Ngu Hanh Son, hop lich trinh nhe.',
            ],
            [
                'name' => 'Son Tra Peninsula',
                'type' => 'outdoor',
                'interest_tags' => ['nature', 'photo', 'viewpoint', 'beach'],
                'cost' => 0,
                'source' => 'Curated Da Nang attractions',
                'area' => 'Son Tra',
                'lat' => 16.1184,
                'lon' => 108.2734,
                'suitability' => 'Cung duong ven bien nhieu diem ngam canh, nen di khi thoi tiet quang.',
            ],
            [
                'name' => 'Marble Mountains',
                'type' => 'outdoor',
                'interest_tags' => ['nature', 'photo', 'culture', 'cave'],
                'cost' => 40000,
                'source' => 'Curated Da Nang attractions',
                'area' => 'Ngu Hanh Son',
                'lat' => 15.9955,
                'lon' => 108.2588,
                'suitability' => 'Cum hang dong va diem nhin gan bai Non Nuoc, de ghe trong nua ngay.',
            ],
            [
                'name' => 'Dragon Bridge Riverside',
                'type' => 'outdoor',
                'interest_tags' => ['photo', 'culture', 'city_walk', 'night'],
                'cost' => 0,
                'source' => 'Curated Da Nang attractions',
                'area' => 'Hai Chau',
                'lat' => 16.0611,
                'lon' => 108.2278,
                'suitability' => 'Diem di dao buoi toi de chup anh va an uong ven song Han.',
            ],
        ],
        'ha long' => [
            [
                'name' => 'Bai Chay Beach',
                'type' => 'outdoor',
                'interest_tags' => ['beach', 'swimming', 'photo', 'relax'],
                'cost' => 0,
                'source' => 'Curated Ha Long attractions',
                'area' => 'Bai Chay',
                'lat' => 20.9537,
                'lon' => 107.0415,
                'suitability' => 'Bai bien cong cong de tam bien va nghi ngan gan khu khach san Bai Chay.',
            ],
            [
                'name' => 'Tuan Chau Beach',
                'type' => 'outdoor',
                'interest_tags' => ['beach', 'swimming', 'resort', 'relax'],
                'cost' => 0,
                'source' => 'Curated Ha Long attractions',
                'area' => 'Tuan Chau',
                'lat' => 20.9236,
                'lon' => 106.9862,
                'suitability' => 'Khu bien gan cang tau va nhieu dich vu nghi duong.',
            ],
            [
                'name' => 'Titop Island Beach',
                'type' => 'outdoor',
                'interest_tags' => ['beach', 'swimming', 'island', 'photo', 'nature'],
                'cost' => 120000,
                'source' => 'Curated Ha Long attractions',
                'area' => 'Ha Long Bay',
                'lat' => 20.8687,
                'lon' => 107.0812,
                'suitability' => 'Diem tam bien tren dao, thuong ket hop tour tham vinh Ha Long.',
            ],
            [
                'name' => 'Sun World Ha Long Water Park',
                'type' => 'outdoor',
                'interest_tags' => ['swimming', 'water_park', 'family', 'entertainment'],
                'cost' => 350000,
                'source' => 'Curated Ha Long attractions',
                'area' => 'Bai Chay',
                'lat' => 20.9561,
                'lon' => 107.0493,
                'suitability' => 'Lua chon vui choi duoi nuoc co to chuc, phu hop gia dinh khi thoi tiet on.',
            ],
        ],
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

    public function fetchLiveHotels(
        string $destination,
        int $limit = 8,
        ?string $checkinDate = null,
        ?string $checkoutDate = null,
        int $adults = 2,
        int $rooms = 1,
        int $children = 0,
        array $childAges = [],
    ): array {
        $canonical = $this->locations->resolve($destination)['canonical_name'];
        $resolved = $this->locations->resolve($canonical);
        $searchRadius = in_array($canonical, ['Da Lat', 'Phu Quoc'], true) || in_array($resolved['kind'] ?? '', ['island', 'town'], true)
            ? 30000
            : 15000;

        $serpApiHotels = $this->serpApiSearchHotels($canonical, $limit, $checkinDate, $checkoutDate, $adults, $rooms, $children, $childAges);
        if ($serpApiHotels !== []) {
            return $serpApiHotels;
        }

        $rapidApiHotels = $this->rapidApiSearchHotels($canonical, $limit, $checkinDate, $checkoutDate, $adults, $rooms, $children, $childAges);
        if ($rapidApiHotels !== []) {
            return $rapidApiHotels;
        }

        $geoQueries = [$canonical];
        foreach ([$resolved['nearest_known_place'] ?? null, $resolved['nearest_bus_hub'] ?? null] as $query) {
            if (is_string($query) && $query !== '' && ! in_array($query, $geoQueries, true)) {
                $geoQueries[] = $query;
            }
        }

        foreach ($geoQueries as $queryDestination) {
            $features = $this->geoapifyPlaces('accommodation.hotel,accommodation.guest_house,accommodation.apartment,accommodation.hostel,accommodation.motel', $queryDestination, $searchRadius, $limit);
            if ($features !== []) {
                return array_map(function (array $feature) use ($canonical): array {
                    $props = $feature['properties'] ?? [];

                    return [
                        'name' => $props['name'] ?? $props['formatted'] ?? 'Hotel',
                        'area' => $props['suburb'] ?? $props['district'] ?? $props['city'] ?? $canonical,
                        'rating' => 4.0,
                        'review_count' => 0,
                        'price_per_night' => 800000,
                        'amenities' => ['wifi', 'breakfast'],
                        'source' => 'Geoapify',
                        'lat' => data_get($feature, 'geometry.coordinates.1'),
                        'lon' => data_get($feature, 'geometry.coordinates.0'),
                    ];
                }, array_slice($features, 0, $limit));
            }
        }

        $coords = $this->geocodeDestination($canonical);
        if (! $coords && ! empty($resolved['nearest_bus_hub'])) {
            $coords = $this->geocodeDestination((string) $resolved['nearest_bus_hub']);
        }
        if (! $coords) {
            return [];
        }
        [$lat, $lon] = $coords;
        $query = $this->overpassQuery(
            'node["tourism"~"hotel|guest_house|hostel|motel|apartment|resort"](around:'.$searchRadius.','.$lat.','.$lon.');'
            .'way["tourism"~"hotel|guest_house|hostel|motel|apartment|resort"](around:'.$searchRadius.','.$lat.','.$lon.');'
            .'relation["tourism"~"hotel|guest_house|hostel|motel|apartment|resort"](around:'.$searchRadius.','.$lat.','.$lon.');'
        );

        $results = [];
        $seen = [];
        foreach ($this->fetchOverpassElements($query) as $item) {
            $tags = $item['tags'] ?? [];
            $name = $tags['name'] ?? $tags['official_name'] ?? '';
            $key = strtolower(trim((string) $name));
            if ($key === '' || strlen($key) < 3 || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $results[] = [
                'name' => $name,
                'area' => $tags['addr:suburb'] ?? $tags['addr:district'] ?? $tags['addr:city'] ?? $canonical,
                'rating' => $this->safeHotelRating($tags),
                'review_count' => 0,
                'price_per_night' => $this->safeHotelPricePerNight($tags),
                'amenities' => ['wifi', 'breakfast'],
                'source' => 'OpenStreetMap',
                'lat' => $item['lat'] ?? data_get($item, 'center.lat'),
                'lon' => $item['lon'] ?? data_get($item, 'center.lon'),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    public function fetchLiveAttractions(string $destination, int $limit = 10): array
    {
        $canonical = $this->locations->resolve($destination)['canonical_name'];
        $features = $this->geoapifyPlaces('tourism.sights,entertainment,museum,leisure.park,natural', $canonical, 18000, $limit);
        $results = array_map(fn (array $feature): array => $this->geoapifyFeatureToAttraction($feature, 'general'), array_slice($features, 0, $limit));
        if ($results !== []) {
            return $this->dedupeAttractions($results);
        }

        $wikipediaResults = $this->wikipediaGeoAttractions($canonical, 14000, $limit);
        if ($wikipediaResults !== []) {
            return $wikipediaResults;
        }

        $coords = $this->geocodeDestination($canonical);
        if (! $coords) {
            return [];
        }
        [$lat, $lon] = $coords;
        $radius = in_array($canonical, ['Da Lat', 'Mang Den', 'Tam Dao'], true) ? 22000 : 18000;
        $query = $this->overpassQuery(
            'node["tourism"](around:'.$radius.','.$lat.','.$lon.');way["tourism"](around:'.$radius.','.$lat.','.$lon.');relation["tourism"](around:'.$radius.','.$lat.','.$lon.');'
            .'node["historic"](around:'.$radius.','.$lat.','.$lon.');way["historic"](around:'.$radius.','.$lat.','.$lon.');relation["historic"](around:'.$radius.','.$lat.','.$lon.');'
            .'node["leisure"](around:'.$radius.','.$lat.','.$lon.');way["leisure"](around:'.$radius.','.$lat.','.$lon.');relation["leisure"](around:'.$radius.','.$lat.','.$lon.');'
            .'node["natural"](around:'.$radius.','.$lat.','.$lon.');way["natural"](around:'.$radius.','.$lat.','.$lon.');relation["natural"](around:'.$radius.','.$lat.','.$lon.');'
            .'node["amenity"~"arts_centre|theatre|cinema|planetarium"](around:'.$radius.','.$lat.','.$lon.');way["amenity"~"arts_centre|theatre|cinema|planetarium"](around:'.$radius.','.$lat.','.$lon.');relation["amenity"~"arts_centre|theatre|cinema|planetarium"](around:'.$radius.','.$lat.','.$lon.');'
        );
        foreach ($this->fetchOverpassElements($query) as $element) {
            $attraction = $this->overpassElementToAttraction($element, true);
            if ($attraction) {
                $results[] = $attraction;
            }
            if (count($results) >= $limit) {
                break;
            }
        }
        return array_slice($this->dedupeAttractions($results), 0, $limit);
    }

    public function fetchCuratedActivityAttractions(string $destination, string $strategy = 'general', int $limit = 10): array
    {
        $slug = Text::asciiFold($this->locations->resolve($destination)['canonical_name']);
        $key = null;
        foreach (array_keys(self::CURATED_ACTIVITY_ATTRACTIONS) as $candidate) {
            if (str_contains($slug, $candidate)) {
                $key = $candidate;
                break;
            }
        }
        if ($key === null) {
            return [];
        }

        $curated = self::CURATED_ACTIVITY_ATTRACTIONS[$key];
        if ($strategy === 'beach_swimming') {
            $curated = array_values(array_filter(
                $curated,
                fn (array $item): bool => array_intersect(['beach', 'swimming', 'water_park'], $item['interest_tags'] ?? []) !== [],
            ));
        }

        return array_slice(array_map(fn (array $item): array => [...$item], $curated), 0, $limit);
    }

    public function fetchActivityAttractions(string $destination, string $strategy = 'general', array $hotelContext = [], int $limit = 12): array
    {
        $canonical = $this->locations->resolve($destination)['canonical_name'];
        if ($strategy !== 'beach_swimming') {
            return $this->fetchLiveAttractions($canonical, $limit);
        }

        $results = $this->fetchCuratedActivityAttractions($canonical, $strategy, $limit);
        $searchDestination = trim((string) ($hotelContext['area'] ?? '')) !== ''
            ? trim((string) $hotelContext['area']).', '.$canonical
            : $canonical;

        foreach ([$searchDestination, $canonical] as $queryDestination) {
            $features = $this->geoapifyPlaces('natural.beach,natural.water,leisure.water_park,leisure.swimming_pool,tourism.sights,entertainment', $queryDestination, 16000, $limit);
            foreach ($features as $feature) {
                $results[] = $this->geoapifyFeatureToAttraction($feature, $strategy);
            }
            if (count($results) >= $limit) {
                break;
            }
        }

        $coords = $this->geocodeDestination($searchDestination) ?: $this->geocodeDestination($canonical);
        if ($coords && count($results) < $limit) {
            [$lat, $lon] = $coords;
            $query = $this->overpassQuery(
                'node["natural"~"beach|bay|water"](around:22000,'.$lat.','.$lon.');way["natural"~"beach|bay|water"](around:22000,'.$lat.','.$lon.');relation["natural"~"beach|bay|water"](around:22000,'.$lat.','.$lon.');'
                .'node["place"~"island|islet"](around:22000,'.$lat.','.$lon.');way["place"~"island|islet"](around:22000,'.$lat.','.$lon.');relation["place"~"island|islet"](around:22000,'.$lat.','.$lon.');'
                .'node["leisure"~"water_park|swimming_pool|beach_resort"](around:22000,'.$lat.','.$lon.');way["leisure"~"water_park|swimming_pool|beach_resort"](around:22000,'.$lat.','.$lon.');relation["leisure"~"water_park|swimming_pool|beach_resort"](around:22000,'.$lat.','.$lon.');'
                .'node["tourism"~"attraction|theme_park"](around:22000,'.$lat.','.$lon.');way["tourism"~"attraction|theme_park"](around:22000,'.$lat.','.$lon.');relation["tourism"~"attraction|theme_park"](around:22000,'.$lat.','.$lon.');'
            );
            foreach ($this->fetchOverpassElements($query) as $element) {
                $attraction = $this->overpassElementToAttraction($element, false);
                if ($attraction) {
                    $results[] = $attraction;
                }
            }
        }

        $deduped = $this->dedupeAttractions($results);
        if (is_numeric($hotelContext['lat'] ?? null) && is_numeric($hotelContext['lon'] ?? null)) {
            foreach ($deduped as &$item) {
                if (is_numeric($item['lat'] ?? null) && is_numeric($item['lon'] ?? null)) {
                    $item['distance_to_hotel_km'] = round($this->distanceKm((float) $hotelContext['lat'], (float) $hotelContext['lon'], (float) $item['lat'], (float) $item['lon']), 1);
                }
            }
            unset($item);
        }

        return array_slice($deduped, 0, $limit);
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

    private function serpApiSearchHotels(
        string $destination,
        int $limit,
        ?string $checkinDate,
        ?string $checkoutDate,
        int $adults,
        int $rooms,
        int $children,
        array $childAges,
    ): array {
        $apiKey = $this->secret('SERPAPI_KEY');
        if (! $apiKey) {
            return [];
        }

        $coords = $this->geocodeDestination($destination);
        $canonical = $this->locations->resolve($destination)['canonical_name'];
        $maxDistanceKm = in_array($canonical, ['Da Lat', 'Phu Quoc', 'Con Dao'], true) ? 45.0 : 30.0;
        [$checkin, $checkout] = $this->hotelDateRange($checkinDate, $checkoutDate);
        $stayNights = $this->nightsBetween($checkin, $checkout);

        $params = [
            'engine' => 'google_hotels',
            'q' => $this->hotelSearchQuery($canonical),
            'check_in_date' => $checkin,
            'check_out_date' => $checkout,
            'adults' => max(1, $adults),
            'children' => max(0, $children),
            'currency' => 'VND',
            'gl' => 'vn',
            'hl' => 'vi',
            'api_key' => $apiKey,
        ];
        if ($rooms > 1) {
            $params['rooms'] = $rooms;
        }
        if ($children > 0) {
            $params['children_ages'] = implode(',', array_slice($childAges ?: array_fill(0, $children, 7), 0, $children));
        }

        try {
            $data = Http::timeout(18)->get(self::SERPAPI_URL, $params)->throw()->json() ?: [];
        } catch (\Throwable) {
            return [];
        }

        $results = [];
        foreach (($data['properties'] ?? []) as $property) {
            $name = $property['name'] ?? null;
            if (! $name) {
                continue;
            }

            $distanceFromDestination = null;
            $hotelLat = data_get($property, 'gps_coordinates.latitude');
            $hotelLon = data_get($property, 'gps_coordinates.longitude');
            if ($coords && $hotelLat !== null && $hotelLon !== null) {
                $distanceFromDestination = $this->distanceKm($coords[0], $coords[1], (float) $hotelLat, (float) $hotelLon);
                if ($distanceFromDestination > $maxDistanceKm) {
                    continue;
                }
            } elseif ($coords) {
                continue;
            }

            $nightlyPrice = $this->hotelPriceValue($property['rate_per_night'] ?? [], 'extracted_lowest', 'extracted_before_taxes_fees');
            $totalPrice = $this->hotelPriceValue($property['total_rate'] ?? [], 'extracted_lowest', 'extracted_before_taxes_fees');
            if (! $nightlyPrice && $totalPrice) {
                $nightlyPrice = (int) round($totalPrice / $stayNights);
            }
            if (! $totalPrice && $nightlyPrice) {
                $totalPrice = $nightlyPrice * $stayNights;
            }
            if (! $nightlyPrice && ! $totalPrice) {
                continue;
            }

            $images = $property['images'] ?? [];
            $firstImage = is_array($images) ? ($images[0] ?? []) : [];
            $nearbyPlaces = $property['nearby_places'] ?? [];
            $area = $canonical;
            if (! empty($nearbyPlaces[0]['name'])) {
                $area = $nearbyPlaces[0]['name'];
            } elseif (! empty($property['neighborhood'])) {
                $area = $property['neighborhood'];
            }

            $results[] = [
                'id' => $property['property_token'] ?? null,
                'name' => $name,
                'area' => $area,
                'room_label' => $property['hotel_class'] ?? '',
                'included_taxes' => ! empty($property['total_rate']),
                'free_cancellation' => false,
                'no_prepayment' => false,
                'rating' => $property['overall_rating'] ?? $property['extracted_hotel_class'] ?? 0,
                'review_word' => '',
                'review_count' => $property['reviews'] ?? 0,
                'price_per_night' => $nightlyPrice,
                'total_price' => $totalPrice,
                'currency' => 'VND',
                'checkin_date' => $checkin,
                'checkout_date' => $checkout,
                'photo_url' => $firstImage['original_image'] ?? $property['thumbnail'] ?? '',
                'booking_link' => $property['link'] ?? $property['serpapi_property_details_link'] ?? '',
                'source' => 'SerpAPI Google Hotels',
                'amenities' => ['booking', 'hotel', 'google_hotels'],
                'price_source' => 'Google Hotels live rate',
                'distance_km' => $distanceFromDestination !== null ? round($distanceFromDestination, 1) : null,
                'search_adults' => $adults,
                'search_children' => $children,
                'search_child_ages' => array_slice($childAges ?: array_fill(0, $children, 7), 0, $children),
                'search_rooms' => $rooms,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function rapidApiSearchHotels(
        string $destination,
        int $limit,
        ?string $checkinDate,
        ?string $checkoutDate,
        int $adults,
        int $rooms,
        int $children,
        array $childAges,
    ): array {
        $headers = $this->rapidApiHeaders();
        $dest = $this->rapidApiDestinationSearch($destination, $headers);
        if (! $headers || ! $dest) {
            return [];
        }

        [$checkin, $checkout] = $this->hotelDateRange($checkinDate, $checkoutDate);
        $params = [
            'dest_id' => $dest['dest_id'] ?? null,
            'search_type' => strtoupper((string) ($dest['search_type'] ?? $dest['dest_type'] ?? 'city')),
            'arrival_date' => $checkin,
            'departure_date' => $checkout,
            'adults' => max(1, $adults),
            'room_qty' => max(1, $rooms),
            'page_number' => 1,
            'units' => 'metric',
            'temperature_unit' => 'c',
            'languagecode' => 'en-us',
            'currency_code' => 'VND',
        ];
        if ($children > 0) {
            $params['children_age'] = implode(',', array_slice($childAges ?: array_fill(0, $children, 7), 0, $children));
        }

        try {
            $data = Http::timeout(18)
                ->withHeaders($headers)
                ->get(self::RAPIDAPI_BASE_URL.'/hotels/searchHotels', $params)
                ->throw()
                ->json() ?: [];
        } catch (\Throwable) {
            return [];
        }

        $results = [];
        foreach (array_slice(data_get($data, 'data.hotels', []), 0, $limit) as $item) {
            $property = $item['property'] ?? [];
            $name = $property['name'] ?? null;
            if (! $name) {
                continue;
            }

            $price = data_get($property, 'priceBreakdown.grossPrice.value', 0);
            $accessibilityLabel = (string) ($item['accessibilityLabel'] ?? '');
            $labelLines = array_values(array_filter(array_map(
                fn (string $line): string => trim($line, " .\u{200e}\u{202c}"),
                preg_split('/\R/u', $accessibilityLabel) ?: [],
            )));
            $area = $property['wishlistName'] ?? $this->locations->resolve($destination)['canonical_name'];
            $roomLabel = '';
            $includedTaxes = false;
            $freeCancellation = false;
            $noPrepayment = false;

            foreach ($labelLines as $line) {
                $lower = strtolower($line);
                if (
                    (str_contains($lower, 'from centre') || str_contains($lower, 'from downtown') || str_contains($lower, 'beachfront') || str_contains($lower, 'city centre') || str_contains($lower, 'suburbs') || str_contains($lower, 'district'))
                    && $area === ($property['wishlistName'] ?? $this->locations->resolve($destination)['canonical_name'])
                ) {
                    $area = $line;
                }
                if (str_contains($lower, 'includes taxes and') || str_contains($lower, 'includes taxes and fees') || str_contains($lower, 'includes taxes and charges')) {
                    $includedTaxes = true;
                }
                if (str_contains($lower, 'free cancellation')) {
                    $freeCancellation = true;
                }
                if (str_contains($lower, 'no prepayment')) {
                    $noPrepayment = true;
                }
                if (! $roomLabel && (str_contains($lower, 'bed') || str_contains($lower, 'bathroom') || str_contains($lower, 'apartment') || str_contains($lower, 'hotel room') || str_contains($lower, 'suite'))) {
                    $roomLabel = $line;
                }
            }

            $results[] = [
                'id' => $property['id'] ?? null,
                'name' => $name,
                'area' => $area,
                'room_label' => $roomLabel,
                'included_taxes' => $includedTaxes,
                'free_cancellation' => $freeCancellation,
                'no_prepayment' => $noPrepayment,
                'rating' => $property['reviewScore'] ?? $property['propertyClass'] ?? $property['accuratePropertyClass'] ?? 0,
                'review_word' => $property['reviewScoreWord'] ?? '',
                'review_count' => $property['reviewCount'] ?? 0,
                'price_per_night' => $price ? (int) round((float) $price) : 0,
                'currency' => data_get($property, 'priceBreakdown.grossPrice.currency') ?: ($property['currency'] ?? 'VND'),
                'checkin_date' => $property['checkinDate'] ?? $checkin,
                'checkout_date' => $property['checkoutDate'] ?? $checkout,
                'photo_url' => data_get($property, 'photoUrls.0', ''),
                'accessibility_label' => $accessibilityLabel,
                'source' => 'RapidAPI booking-com15',
                'amenities' => ['booking', 'hotel'],
                'search_adults' => $adults,
                'search_children' => $children,
                'search_child_ages' => array_slice($childAges ?: array_fill(0, $children, 7), 0, $children),
                'search_rooms' => $rooms,
            ];
        }

        return $results;
    }

    private function rapidApiHeaders(): ?array
    {
        $apiKey = $this->secret('RAPIDAPI_KEY');
        if (! $apiKey) {
            return null;
        }

        return [
            'x-rapidapi-key' => $apiKey,
            'x-rapidapi-host' => $this->secret('RAPIDAPI_HOST') ?: 'booking-com15.p.rapidapi.com',
        ];
    }

    private function rapidApiDestinationSearch(string $destination, ?array $headers): ?array
    {
        if (! $headers) {
            return null;
        }

        try {
            $data = Http::timeout(12)
                ->withHeaders($headers)
                ->get(self::RAPIDAPI_BASE_URL.'/hotels/searchDestination', [
                    'query' => $destination,
                    'locale' => 'en-gb',
                ])
                ->throw()
                ->json() ?: [];
        } catch (\Throwable) {
            return null;
        }

        $rows = $data['data'] ?? [];
        if ($rows === []) {
            return null;
        }

        $resolved = strtolower($this->locations->resolve($destination)['canonical_name']);
        foreach ($rows as $row) {
            $label = strtolower(($row['label'] ?? '').' '.($row['name'] ?? ''));
            if ($resolved !== '' && str_contains($label, $resolved)) {
                return $row;
            }
        }

        return $rows[0];
    }

    private function hotelDateRange(?string $checkinDate, ?string $checkoutDate): array
    {
        $checkin = $this->validDate($checkinDate) ?: now()->addDays(14)->toDateString();
        $checkout = $this->validDate($checkoutDate) ?: now()->addDays(15)->toDateString();
        if (strtotime($checkout) <= strtotime($checkin)) {
            $checkout = date('Y-m-d', strtotime($checkin.' +1 day'));
        }

        return [$checkin, $checkout];
    }

    private function validDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function nightsBetween(string $checkin, string $checkout): int
    {
        $seconds = max(strtotime($checkout) - strtotime($checkin), 86400);

        return max((int) round($seconds / 86400), 1);
    }

    private function hotelSearchQuery(string $destination): string
    {
        return [
            'Da Lat' => 'Da Lat Lam Dong Vietnam hotels',
            'Da Nang' => 'Da Nang Vietnam hotels',
            'Phu Quoc' => 'Phu Quoc Vietnam hotels',
            'Nha Trang' => 'Nha Trang Khanh Hoa Vietnam hotels',
            'Hue' => 'Hue Vietnam hotels',
            'Hoi An' => 'Hoi An Quang Nam Vietnam hotels',
            'Ha Noi' => 'Hanoi Vietnam hotels',
            'Ho Chi Minh' => 'Ho Chi Minh City Vietnam hotels',
        ][$destination] ?? "{$destination} Vietnam hotels";
    }

    private function hotelPriceValue(array $price, string ...$keys): int
    {
        foreach ($keys as $key) {
            $value = $price[$key] ?? null;
            if (is_numeric($value) && (float) $value > 0) {
                return (int) round((float) $value);
            }
        }

        return 0;
    }

    private function safeHotelRating(array $tags): float
    {
        $stars = $tags['stars'] ?? $tags['hotel:stars'] ?? $tags['rating'] ?? '';
        if (is_numeric($stars)) {
            return (float) $stars;
        }
        $tourism = $tags['tourism'] ?? '';

        return match ($tourism) {
            'hotel' => 4.0,
            'resort', 'apartment', 'guest_house' => 3.8,
            'hostel', 'motel' => 3.5,
            default => 3.7,
        };
    }

    private function safeHotelPricePerNight(array $tags): int
    {
        return match ($tags['tourism'] ?? '') {
            'hotel' => 850000,
            'resort' => 1200000,
            'apartment', 'guest_house' => 650000,
            'hostel', 'motel' => 400000,
            default => 700000,
        };
    }

    private function wikipediaGeoAttractions(string $destination, int $radius = 10000, int $limit = 10): array
    {
        $coords = $this->geocodeDestination($destination);
        if (! $coords) {
            return [];
        }
        [$lat, $lon] = $coords;
        $radius = min(max($radius, 10), 10000);
        $results = [];
        $seen = [];

        foreach (self::WIKIPEDIA_URLS as $lang => $url) {
            if (count($results) >= $limit) {
                break;
            }

            try {
                $geoData = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'TravelPlannerA2A/1.0 (local travel planning app; contact: dev@example.com)'])
                    ->get($url, [
                        'action' => 'query',
                        'list' => 'geosearch',
                        'gscoord' => "{$lat}|{$lon}",
                        'gsradius' => $radius,
                        'gslimit' => $limit * 2,
                        'format' => 'json',
                        'origin' => '*',
                    ])
                    ->throw()
                    ->json() ?: [];
            } catch (\Throwable) {
                continue;
            }

            $rows = data_get($geoData, 'query.geosearch', []);
            $pageIds = array_values(array_filter(array_map(
                fn (array $row): string => (string) ($row['pageid'] ?? ''),
                is_array($rows) ? $rows : [],
            )));
            if ($pageIds === []) {
                continue;
            }

            try {
                $pageData = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'TravelPlannerA2A/1.0 (local travel planning app; contact: dev@example.com)'])
                    ->get($url, [
                        'action' => 'query',
                        'pageids' => implode('|', array_slice($pageIds, 0, 20)),
                        'prop' => 'pageimages|pageterms',
                        'piprop' => 'thumbnail',
                        'pithumbsize' => 900,
                        'format' => 'json',
                        'origin' => '*',
                    ])
                    ->throw()
                    ->json() ?: [];
            } catch (\Throwable) {
                continue;
            }

            $pages = data_get($pageData, 'query.pages', []);
            foreach ($rows as $row) {
                if (count($results) >= $limit) {
                    break;
                }
                $page = is_array($pages) ? ($pages[(string) ($row['pageid'] ?? '')] ?? []) : [];
                $title = trim((string) (($page['title'] ?? null) ?: ($row['title'] ?? '')));
                $key = Text::asciiFold($title);
                if ($title === '' || isset($seen[$key])) {
                    continue;
                }

                $description = trim((string) data_get($page, 'terms.description.0', ''));
                if ($this->isNoisyAttraction($title, $description)) {
                    continue;
                }

                $seen[$key] = true;
                $thumbnail = (string) data_get($page, 'thumbnail.source', '');
                $combined = Text::asciiFold($description.' '.$title);
                $tags = ['explore', 'photo'];
                foreach (['den', 'chua', 'palace', 'temple', 'church', 'museum', 'historic'] as $term) {
                    if (str_contains($combined, $term)) {
                        array_push($tags, 'history', 'culture');
                        break;
                    }
                }
                foreach (['lake', 'mountain', 'waterfall', 'valley', 'ho ', 'nui', 'thac', 'vuon'] as $term) {
                    if (str_contains($combined, $term)) {
                        $tags[] = 'nature';
                        break;
                    }
                }

                $results[] = [
                    'name' => $title,
                    'type' => 'outdoor',
                    'interest_tags' => array_values(array_unique($tags)),
                    'cost' => 0,
                    'source' => 'Wikipedia '.$lang,
                    'photo_url' => $thumbnail,
                    'suitability' => $description,
                ];
            }
        }

        return $results;
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

    private function geoapifyFeatureToAttraction(array $feature, string $strategy): array
    {
        $props = $feature['properties'] ?? [];
        $coords = data_get($feature, 'geometry.coordinates', []);
        $name = $props['name'] ?? $props['formatted'] ?? 'Attraction';
        $categoryText = strtolower(implode(' ', $props['categories'] ?? []));
        $nameSlug = Text::asciiFold((string) $name);
        $tags = ['explore'];
        $type = 'outdoor';
        $cost = 0;

        if (
            str_contains($categoryText, 'beach')
            || str_contains($categoryText, 'bay')
            || str_contains($categoryText, 'island')
            || str_contains($categoryText, 'natural')
            || str_contains($nameSlug, 'beach')
            || str_contains($nameSlug, 'bai')
            || str_contains($nameSlug, 'dao')
        ) {
            array_push($tags, 'beach', 'swimming', 'nature', 'photo');
        }
        if (str_contains($categoryText, 'water_park') || str_contains($categoryText, 'swimming_pool') || str_contains($nameSlug, 'cong vien nuoc')) {
            array_push($tags, 'swimming', 'water_park', 'entertainment');
            $cost = 250000;
        }
        if (str_contains($categoryText, 'museum')) {
            array_push($tags, 'history', 'culture');
            $type = 'indoor';
            $cost = 100000;
        }
        if (str_contains($categoryText, 'park') && ! str_contains($categoryText, 'water_park')) {
            array_push($tags, 'nature', 'photo');
        }
        if (str_contains($categoryText, 'tourism') || str_contains($categoryText, 'sights')) {
            $tags[] = 'photo';
        }
        if ($strategy === 'beach_swimming' && array_intersect(['beach', 'swimming'], $tags) === []) {
            $tags[] = 'low_activity_match';
        }

        return [
            'name' => $name,
            'type' => $type,
            'interest_tags' => array_values(array_unique($tags)),
            'cost' => $cost,
            'source' => 'Geoapify',
            'area' => $props['suburb'] ?? $props['district'] ?? $props['city'] ?? '',
            'lat' => is_array($coords) ? ($coords[1] ?? null) : null,
            'lon' => is_array($coords) ? ($coords[0] ?? null) : null,
            'suitability' => '',
        ];
    }

    private function overpassElementToAttraction(array $item, bool $strictGeneralFilters): ?array
    {
        $tags = $item['tags'] ?? [];
        $name = $tags['name'] ?? $tags['official_name'] ?? '';
        if (! is_string($name) || strlen(trim($name)) < 3) {
            return null;
        }
        if ($this->isNoisyAttraction($name, implode(' ', array_map(
            fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $tags,
        )))) {
            return null;
        }

        $tourism = (string) ($tags['tourism'] ?? '');
        $historic = (string) ($tags['historic'] ?? '');
        $leisure = (string) ($tags['leisure'] ?? '');
        $natural = (string) ($tags['natural'] ?? '');
        $amenity = (string) ($tags['amenity'] ?? '');
        $place = (string) ($tags['place'] ?? '');
        $category = $tourism ?: ($historic ?: ($leisure ?: ($natural ?: ($amenity ?: ($place ?: 'attraction')))));
        $key = strtolower(trim($name));

        if ($strictGeneralFilters) {
            if (in_array($category, ['hotel', 'guest_house', 'hostel', 'motel', 'apartment', 'resort', 'camp_site', 'chalet'], true)) {
                return null;
            }
            if (in_array($category, ['information', 'travel_agency', 'ticket', 'swimming_pool', 'sports_centre', 'pitch', 'fitness_centre'], true)) {
                return null;
            }
            foreach (['phong ve', 've may bay', 'hotel', 'homestay', 'hostel', 'villa', 'resort'] as $term) {
                if (str_contains($key, $term)) {
                    return null;
                }
            }
            if (! $tourism && ! $historic && ! $leisure && ! $natural) {
                return null;
            }
        }

        $nameSlug = Text::asciiFold($name);
        $tagsOut = ['explore', $category];
        $type = 'outdoor';
        $cost = 0;
        if (in_array($category, ['beach', 'bay', 'islet', 'water', 'island'], true) || str_contains($nameSlug, 'beach') || str_contains($nameSlug, 'bai') || str_contains($nameSlug, 'dao')) {
            array_push($tagsOut, 'beach', 'swimming', 'nature', 'photo');
        }
        if (in_array($category, ['water_park', 'swimming_pool'], true)) {
            array_push($tagsOut, 'swimming', 'water_park', 'entertainment');
            $cost = 250000;
        }
        if (in_array($category, ['viewpoint', 'park', 'garden', 'peak', 'waterfall', 'cave'], true)) {
            array_push($tagsOut, 'photo', 'nature');
        }
        if (in_array($category, ['museum', 'memorial', 'monument', 'castle', 'artwork', 'temple', 'pagoda', 'historic'], true)) {
            array_push($tagsOut, 'history', 'culture');
            $type = in_array($category, ['museum', 'gallery', 'artwork'], true) ? 'indoor' : 'outdoor';
            $cost = in_array($category, ['museum', 'gallery'], true) ? 100000 : $cost;
        }

        $center = $item['center'] ?? [];
        return [
            'name' => $name,
            'type' => $type,
            'interest_tags' => array_values(array_unique($tagsOut)),
            'cost' => $cost,
            'source' => 'OpenStreetMap',
            'area' => $tags['addr:suburb'] ?? $tags['addr:district'] ?? $tags['addr:city'] ?? '',
            'lat' => $item['lat'] ?? $center['lat'] ?? null,
            'lon' => $item['lon'] ?? $center['lon'] ?? null,
            'suitability' => '',
        ];
    }

    private function overpassQuery(string $filterBody): string
    {
        return "[out:json][timeout:8];({$filterBody});out center tags 80;";
    }

    private function fetchOverpassElements(string $query): array
    {
        foreach (self::OVERPASS_URLS as $url) {
            try {
                $data = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])
                    ->withBody($query, 'text/plain')
                    ->post($url)
                    ->throw()
                    ->json() ?: [];
                if (! empty($data['elements']) && is_array($data['elements'])) {
                    return $data['elements'];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    private function dedupeAttractions(array $items): array
    {
        $seen = [];
        $results = [];
        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            $context = (string) ($item['suitability'] ?? '').' '.(string) ($item['source'] ?? '').' '.implode(' ', (array) ($item['interest_tags'] ?? []));
            if ($this->isNoisyAttraction($name, $context)) {
                continue;
            }
            $key = Text::asciiFold($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $results[] = $item;
        }
        return $results;
    }

    private function isNoisyAttraction(string $name, string $context = ''): bool
    {
        $raw = mb_strtolower(trim($name.' '.$context));
        $text = Text::asciiFold($raw);
        $haystack = $text.' '.$raw;
        $nameVariants = [Text::asciiFold($name), mb_strtolower($name), $haystack];
        if ($text === '' || mb_strlen(trim($name)) < 3) {
            return true;
        }

        $hardRejects = [
            'hotel',
            'khach san',
            'homestay',
            'hostel',
            'motel',
            'resort',
            'villa',
            'airport',
            'san bay',
            'sân bay',
            'cang hang khong',
            'cảng hàng không',
            'benh vien',
            'bệnh viện',
            'phong ve',
            'phòng vé',
            've may bay',
            'vé máy bay',
            'travel agency',
            'ticket office',
            'booking',
            'bieu tinh',
            'biểu tình',
            'su kien',
            'sự kiện',
        ];
        foreach ($hardRejects as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        $adminPatterns = [
            '/\((?:phuong|phường|quan|quận|xa|xã|thi tran|thị trấn|thi xa|thị xã|thanh pho thuoc tinh|thành phố thuộc tỉnh)\)/u',
            '/\b(?:phuong|phường|ward|commune)\b/u',
            '/\b(?:administrative division|district of|ward of|commune of)\b/u',
            '/\bdistrict\b/u',
            '/thanh pho thuoc tinh|thành phố thuộc tỉnh/u',
            '/^[^,]{2,40},\s*(?:da nang|đà nẵng|hue|huế|hoi an|hội an|nha trang|ha long|hạ long|phu quoc|phú quốc)$/u',
            '/^hoi an (?:dong|tay|nam|bac)\b/u',
        ];
        foreach ($adminPatterns as $pattern) {
            foreach ($nameVariants as $candidate) {
                if (preg_match($pattern, $candidate) === 1) {
                    return true;
                }
            }
        }

        $administrativeCategory = preg_match('/\b(?:administrative|city|county|state|postcode)\b/u', $haystack) === 1;
        $looksLikeSubPlace = preg_match('/^[^,]{2,40},\s*[^,]{2,40}$/u', Text::asciiFold($name)) === 1;
        if ($administrativeCategory && $looksLikeSubPlace) {
            return true;
        }

        return false;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radius = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);
        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLon / 2) ** 2;

        return $radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
