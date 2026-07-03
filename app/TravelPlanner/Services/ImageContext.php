<?php

namespace App\TravelPlanner\Services;

use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\TravelPlan;
use Illuminate\Support\Facades\Http;

final class ImageContext
{
    private const PEXELS_URL = 'https://api.pexels.com/v1/search';
    private const WIKIMEDIA_COMMONS_URL = 'https://commons.wikimedia.org/w/api.php';
    private const SERPAPI_URL = 'https://serpapi.com/search.json';
    private const FALLBACK_DESTINATION = 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1600&q=80';
    private const FALLBACK_HOTEL = 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=80';
    private const FALLBACK_ATTRACTION = 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80';

    private const DESTINATION_QUERY_HINTS = [
        'Da Nang' => 'Da Nang Vietnam beach skyline dragon bridge',
        'Da Lat' => 'Da Lat Vietnam mountain lake pine forest travel',
        'Ha Noi' => 'Ha Noi Vietnam old quarter hoan kiem travel',
        'Ho Chi Minh' => 'Ho Chi Minh City Vietnam skyline travel',
        'Hue' => 'Hue Vietnam imperial city travel',
        'Hoi An' => 'Hoi An Vietnam ancient town lantern travel',
        'Nha Trang' => 'Nha Trang Vietnam beach skyline travel',
        'Can Tho' => 'Can Tho Vietnam riverside floating market travel',
        'Vung Tau' => 'Vung Tau Vietnam beach coastal city travel',
    ];

    private const HOTEL_AREA_HINTS = [
        'Da Nang' => [
            'luxury hotel room Da Nang beach resort',
            'boutique hotel interior Da Nang',
            'modern hotel bedroom Da Nang Vietnam',
        ],
        'Da Lat' => [
            'cozy hotel room Da Lat mountain view',
            'boutique stay Da Lat interior',
            'pine hill hotel Da Lat room',
        ],
        'Ha Noi' => [
            'boutique hotel room Ha Noi old quarter',
            'luxury hotel Hanoi interior',
            'elegant hotel suite Hanoi',
        ],
    ];

    private const ATTRACTION_TYPE_HINTS = [
        'beach' => 'beach coast tropical travel',
        'viewpoint' => 'mountain viewpoint panorama travel',
        'nature' => 'nature landscape travel',
        'history' => 'historic architecture travel',
        'culture' => 'cultural landmark travel',
        'museum' => 'museum architecture travel',
    ];

    private const ATTRACTION_QUERY_HINTS = [
        'Hai Van Pass' => 'Hai Van Pass Da Nang Vietnam mountain road viewpoint',
        'Ban Co Peak' => 'Ban Co Peak Son Tra Da Nang Vietnam viewpoint',
        'Marble Mountains' => 'Marble Mountains Thuy Son Da Nang Vietnam cave temple',
        'Tam Dao Belvedere Resort' => 'Tam Dao resort mountain view Vietnam',
        'Tam Dao' => 'Tam Dao Vinh Phuc Vietnam mountain town clouds',
        'Xuan Huong Lake' => 'Xuan Huong Lake Da Lat Vietnam lakeside',
        'Langbiang' => 'Langbiang Da Lat Vietnam mountain viewpoint',
        'Da Lat Railway Station' => 'Da Lat Railway Station Vietnam yellow colonial architecture',
        'My Khe Beach' => 'My Khe Beach Da Nang Vietnam coastline',
        'Non Nuoc Beach' => 'Non Nuoc Beach Da Nang Vietnam coastline',
        'Bai Chay Beach' => 'Bai Chay Beach Ha Long Vietnam',
    ];

    public function build(?TravelPlan $plan): array
    {
        if (! $plan) {
            return [
                'destination_hero_image' => self::FALLBACK_DESTINATION,
                'hotel_images' => [],
                'attraction_images' => [],
                'fallback_destination_image' => self::FALLBACK_DESTINATION,
                'fallback_hotel_image' => self::FALLBACK_HOTEL,
                'fallback_attraction_image' => self::FALLBACK_ATTRACTION,
                'destination_name' => '',
            ];
        }

        $hotelImages = [];
        foreach ($plan->hotels as $hotel) {
            if (! $hotel instanceof Recommendation) {
                continue;
            }
            $hotelImages[$hotel->title] = $this->isUsableImageUrl($hotel->imageUrl)
                ? $hotel->imageUrl
                : $this->hotelImage($hotel->title, $plan->destination);
        }

        $attractionImages = [];
        foreach ($plan->attractions as $attraction) {
            if (! $attraction instanceof Recommendation) {
                continue;
            }
            $attractionImages[$attraction->title] = $this->isUsableImageUrl($attraction->imageUrl)
                ? $attraction->imageUrl
                : $this->attractionImage($attraction->title, $plan->destination);
        }

        return [
            'destination_hero_image' => $this->destinationImage($plan->destination),
            'hotel_images' => $hotelImages,
            'attraction_images' => $attractionImages,
            'fallback_destination_image' => self::FALLBACK_DESTINATION,
            'fallback_hotel_image' => self::FALLBACK_HOTEL,
            'fallback_attraction_image' => self::FALLBACK_ATTRACTION,
            'destination_name' => $plan->destination,
        ];
    }

    public function destinationImage(string $destination): string
    {
        $destination = trim($destination) ?: 'travel destination';
        $query = self::DESTINATION_QUERY_HINTS[$destination] ?? "{$destination} Vietnam travel landscape skyline";

        return $this->pexelsSearch($query, self::FALLBACK_DESTINATION);
    }

    public function hotelImage(string $hotelName, string $destination): string
    {
        $destination = trim($destination) ?: 'travel';
        $originalName = trim($hotelName) ?: 'hotel';
        $hotelNameLower = strtolower($originalName);

        $serpImage = $this->serpApiImageSearch("{$originalName} {$destination} hotel", self::FALLBACK_HOTEL);
        if ($serpImage !== self::FALLBACK_HOTEL) {
            return $serpImage;
        }

        $exactImage = $this->pexelsSearch("{$originalName} {$destination} hotel exterior", self::FALLBACK_HOTEL);
        if ($exactImage !== self::FALLBACK_HOTEL) {
            return $exactImage;
        }

        if (str_contains($hotelNameLower, 'beach') || str_contains($hotelNameLower, 'sea') || str_contains($hotelNameLower, 'coast') || str_contains($hotelNameLower, 'resort')) {
            return $this->pexelsSearch("beach resort hotel room {$destination}", self::FALLBACK_HOTEL);
        }
        if (str_contains($hotelNameLower, 'boutique') || str_contains($hotelNameLower, 'central') || str_contains($hotelNameLower, 'old quarter') || str_contains($hotelNameLower, 'comfort')) {
            return $this->pexelsSearch("boutique hotel room interior {$destination}", self::FALLBACK_HOTEL);
        }
        if (! empty(self::HOTEL_AREA_HINTS[$destination][0])) {
            return $this->pexelsSearch(self::HOTEL_AREA_HINTS[$destination][0], self::FALLBACK_HOTEL);
        }

        return $this->pexelsSearch("luxury hotel room interior {$destination}", self::FALLBACK_HOTEL);
    }

    public function attractionImage(string $attractionName, string $destination): string
    {
        $attractionName = trim($attractionName) ?: 'attraction';
        $destination = trim($destination) ?: 'travel';

        $directImage = $this->wikimediaImageSearch("{$attractionName} {$destination} Vietnam", self::FALLBACK_ATTRACTION);
        if ($directImage !== self::FALLBACK_ATTRACTION) {
            return $directImage;
        }

        $serpImage = $this->serpApiImageSearch("{$attractionName} {$destination} Vietnam", self::FALLBACK_ATTRACTION);
        if ($serpImage !== self::FALLBACK_ATTRACTION) {
            return $serpImage;
        }

        $exactQuery = self::ATTRACTION_QUERY_HINTS[$attractionName] ?? null;
        if ($exactQuery) {
            $image = $this->wikimediaImageSearch($exactQuery, self::FALLBACK_ATTRACTION);
            if ($image !== self::FALLBACK_ATTRACTION) {
                return $image;
            }

            return $this->pexelsSearch($exactQuery, self::FALLBACK_ATTRACTION);
        }

        $lower = strtolower($attractionName);
        $hint = 'landmark travel';
        foreach (self::ATTRACTION_TYPE_HINTS as $key => $value) {
            if (str_contains($lower, $key)) {
                $hint = $value;
                break;
            }
        }

        $image = $this->pexelsSearch("{$attractionName} {$destination} Vietnam {$hint}", self::FALLBACK_ATTRACTION);
        if ($image !== self::FALLBACK_ATTRACTION) {
            return $image;
        }

        return $this->pexelsSearch("{$destination} Vietnam {$hint}", self::FALLBACK_ATTRACTION);
    }

    private function pexelsSearch(string $query, string $fallback): string
    {
        $apiKey = $this->secret('PEXELS_API_KEY');
        if (! $apiKey) {
            return $fallback;
        }

        try {
            $data = Http::timeout(5)
                ->withHeaders(['Authorization' => $apiKey])
                ->get(self::PEXELS_URL, [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                    'size' => 'large',
                ])
                ->throw()
                ->json() ?: [];
            $src = data_get($data, 'photos.0.src', []);

            foreach (['landscape', 'large', 'medium'] as $key) {
                $url = is_array($src) ? ($src[$key] ?? null) : null;
                if ($this->isUsableImageUrl($url)) {
                    return $url;
                }
            }
        } catch (\Throwable) {
            //
        }

        return $fallback;
    }

    private function wikimediaImageSearch(string $query, string $fallback): string
    {
        try {
            $data = Http::timeout(4)
                ->withHeaders(['User-Agent' => 'TravelPlannerA2A/1.0 (local travel planning app; contact: dev@example.com)'])
                ->get(self::WIKIMEDIA_COMMONS_URL, [
                    'action' => 'query',
                    'generator' => 'search',
                    'gsrsearch' => $query,
                    'gsrnamespace' => 6,
                    'gsrlimit' => 6,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|mime',
                    'iiurlwidth' => 1000,
                    'format' => 'json',
                    'origin' => '*',
                ])
                ->throw()
                ->json() ?: [];

            foreach (data_get($data, 'query.pages', []) as $page) {
                $info = data_get($page, 'imageinfo.0', []);
                $mime = (string) data_get($info, 'mime', '');
                $url = data_get($info, 'thumburl') ?: data_get($info, 'url');
                if (str_starts_with($mime, 'image/') && $this->isUsableImageUrl($url)) {
                    return $url;
                }
            }
        } catch (\Throwable) {
            //
        }

        return $fallback;
    }

    private function serpApiImageSearch(string $query, string $fallback): string
    {
        $apiKey = $this->secret('SERPAPI_KEY');
        if (! $apiKey) {
            return $fallback;
        }

        try {
            $data = Http::timeout(6)
                ->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])
                ->get(self::SERPAPI_URL, [
                    'engine' => 'google_images',
                    'q' => $query,
                    'gl' => 'vn',
                    'hl' => 'vi',
                    'ijn' => '0',
                    'api_key' => $apiKey,
                ])
                ->throw()
                ->json() ?: [];

            foreach (array_slice($data['images_results'] ?? [], 0, 6) as $item) {
                $url = $item['original'] ?? $item['thumbnail'] ?? null;
                if ($this->isUsableImageUrl($url)) {
                    return $url;
                }
            }
        } catch (\Throwable) {
            //
        }

        return $fallback;
    }

    private function isUsableImageUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $lower = strtolower($url);

        return (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) && ! str_ends_with($lower, '.svg');
    }

    private function secret(string $name): ?string
    {
        $value = env($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value, " \t\n\r\0\x0B\"'");
    }
}
