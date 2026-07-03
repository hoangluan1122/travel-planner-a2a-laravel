<?php

namespace Tests\Feature;

use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\TravelPlan;
use App\TravelPlanner\Services\ImageContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ImageContextParityTest extends TestCase
{
    public function test_build_image_context_uses_live_image_providers_and_existing_urls(): void
    {
        $this->setEnv('PEXELS_API_KEY', 'pexels-test-key');
        $this->setEnv('SERPAPI_KEY', 'serpapi-test-key');

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    ['src' => ['landscape' => 'https://img.example/pexels-landscape.jpg']],
                ],
            ], 200),
            'commons.wikimedia.org/w/api.php*' => Http::response([
                'query' => [
                    'pages' => [
                        '1' => ['imageinfo' => [['mime' => 'image/svg+xml', 'url' => 'https://img.example/map.svg']]],
                        '2' => ['imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://img.example/wiki-thumb.jpg']]],
                    ],
                ],
            ], 200),
            'serpapi.com/search.json*' => Http::response([
                'images_results' => [
                    ['original' => 'https://img.example/google-hotel.jpg'],
                ],
            ], 200),
        ]);

        $plan = new TravelPlan(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            days: 3,
            weatherSummary: '',
            weatherExtra: [],
            transportOptions: [],
            hotels: [
                new Recommendation('Existing Hotel', 'Has image', imageUrl: 'https://img.example/existing-hotel.jpg'),
                new Recommendation('Beach Resort', 'Needs lookup'),
            ],
            attractions: [
                new Recommendation('My Khe Beach', 'Needs lookup'),
                new Recommendation('Existing Attraction', 'Has image', imageUrl: 'https://img.example/existing-attraction.jpg'),
            ],
            dailyItinerary: [],
            estimatedCost: 0,
            finalRecommendation: '',
        );

        $context = app(ImageContext::class)->build($plan);

        $this->assertSame('https://img.example/pexels-landscape.jpg', $context['destination_hero_image']);
        $this->assertSame('https://img.example/existing-hotel.jpg', $context['hotel_images']['Existing Hotel']);
        $this->assertSame('https://img.example/google-hotel.jpg', $context['hotel_images']['Beach Resort']);
        $this->assertSame('https://img.example/wiki-thumb.jpg', $context['attraction_images']['My Khe Beach']);
        $this->assertSame('https://img.example/existing-attraction.jpg', $context['attraction_images']['Existing Attraction']);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'api.pexels.com')
            && $request['query'] === 'Da Nang Vietnam beach skyline dragon bridge');
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'serpapi.com/search.json')
            && $request['engine'] === 'google_images'
            && str_contains($request['q'], 'Beach Resort Da Nang hotel'));
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'commons.wikimedia.org')
            && str_contains($request['gsrsearch'], 'My Khe Beach Da Nang Vietnam'));
    }

    public function test_image_context_falls_back_without_provider_keys(): void
    {
        $this->setEnv('PEXELS_API_KEY', '');
        $this->setEnv('SERPAPI_KEY', '');

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $context = app(ImageContext::class)->build(new TravelPlan(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            days: 1,
            weatherSummary: '',
            weatherExtra: [],
            transportOptions: [],
            hotels: [new Recommendation('Any Hotel', '')],
            attractions: [new Recommendation('Any Place', '')],
            dailyItinerary: [],
            estimatedCost: 0,
            finalRecommendation: '',
        ));

        $this->assertStringContainsString('images.unsplash.com', $context['destination_hero_image']);
        $this->assertStringContainsString('images.unsplash.com', $context['hotel_images']['Any Hotel']);
        $this->assertStringContainsString('images.unsplash.com', $context['attraction_images']['Any Place']);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
