<?php

namespace Tests\Feature;

use App\TravelPlanner\Agents\HotelAgent;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HotelProviderParityTest extends TestCase
{
    public function test_serpapi_google_hotels_are_preferred_and_distance_filtered(): void
    {
        $this->setEnv('SERPAPI_KEY', 'serpapi-test-key');
        $this->setEnv('RAPIDAPI_KEY', '');
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake([
            'serpapi.com/search.json*' => Http::response([
                'properties' => [
                    [
                        'name' => 'Near Beach Hotel',
                        'property_token' => 'near-1',
                        'overall_rating' => 4.6,
                        'reviews' => 321,
                        'rate_per_night' => ['extracted_lowest' => 900000],
                        'total_rate' => ['extracted_lowest' => 1800000],
                        'gps_coordinates' => ['latitude' => 16.0617, 'longitude' => 108.2468],
                        'nearby_places' => [['name' => 'My Khe']],
                        'images' => [['original_image' => 'https://img.example/near.jpg']],
                        'link' => 'https://booking.example/near',
                    ],
                    [
                        'name' => 'Far Away Hotel',
                        'overall_rating' => 5,
                        'rate_per_night' => ['extracted_lowest' => 500000],
                        'gps_coordinates' => ['latitude' => 10.8231, 'longitude' => 106.6297],
                    ],
                ],
            ], 200),
        ]);

        $hotels = app(LiveTravelService::class)->fetchLiveHotels(
            destination: 'Da Nang',
            limit: 8,
            checkinDate: '2026-07-07',
            checkoutDate: '2026-07-09',
            adults: 2,
            rooms: 1,
        );

        $this->assertCount(1, $hotels);
        $this->assertSame('Near Beach Hotel', $hotels[0]['name']);
        $this->assertSame('SerpAPI Google Hotels', $hotels[0]['source']);
        $this->assertSame(900000, $hotels[0]['price_per_night']);
        $this->assertSame(1800000, $hotels[0]['total_price']);
        $this->assertSame('https://img.example/near.jpg', $hotels[0]['photo_url']);
        $this->assertLessThan(30, $hotels[0]['distance_km']);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'serpapi.com/search.json')
            && $request['engine'] === 'google_hotels'
            && $request['q'] === 'Da Nang Vietnam hotels'
            && $request['check_in_date'] === '2026-07-07'
            && $request['check_out_date'] === '2026-07-09'
            && $request['currency'] === 'VND');
    }

    public function test_rapidapi_booking_hotels_are_used_when_google_hotels_empty(): void
    {
        $this->setEnv('SERPAPI_KEY', '');
        $this->setEnv('RAPIDAPI_KEY', 'rapid-test-key');
        $this->setEnv('RAPIDAPI_HOST', 'booking-com15.p.rapidapi.com');
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake([
            'booking-com15.p.rapidapi.com/api/v1/hotels/searchDestination*' => Http::response([
                'data' => [
                    ['dest_id' => '123', 'search_type' => 'city', 'label' => 'Da Nang Vietnam', 'name' => 'Da Nang'],
                ],
            ], 200),
            'booking-com15.p.rapidapi.com/api/v1/hotels/searchHotels*' => Http::response([
                'data' => [
                    'hotels' => [
                        [
                            'accessibilityLabel' => "1.2 km from centre\nDeluxe room with 1 bed\nIncludes taxes and fees\nFree cancellation\nNo prepayment needed",
                            'property' => [
                                'id' => 99,
                                'name' => 'Rapid Bay Hotel',
                                'wishlistName' => 'Da Nang',
                                'reviewScore' => 8.8,
                                'reviewScoreWord' => 'Excellent',
                                'reviewCount' => 456,
                                'priceBreakdown' => [
                                    'grossPrice' => ['value' => 1200000, 'currency' => 'VND'],
                                ],
                                'checkinDate' => '2026-07-07',
                                'checkoutDate' => '2026-07-08',
                                'photoUrls' => ['https://img.example/rapid.jpg'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $hotels = app(LiveTravelService::class)->fetchLiveHotels(
            destination: 'Da Nang',
            checkinDate: '2026-07-07',
            checkoutDate: '2026-07-08',
            adults: 2,
            rooms: 1,
            children: 1,
            childAges: [6],
        );

        $this->assertCount(1, $hotels);
        $this->assertSame('Rapid Bay Hotel', $hotels[0]['name']);
        $this->assertSame('RapidAPI booking-com15', $hotels[0]['source']);
        $this->assertSame('1.2 km from centre', $hotels[0]['area']);
        $this->assertSame('Deluxe room with 1 bed', $hotels[0]['room_label']);
        $this->assertTrue($hotels[0]['included_taxes']);
        $this->assertTrue($hotels[0]['free_cancellation']);
        $this->assertTrue($hotels[0]['no_prepayment']);
        $this->assertSame([6], $hotels[0]['search_child_ages']);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'searchHotels')
            && $request['children_age'] === '6'
            && $request['currency_code'] === 'VND');
    }

    public function test_hotel_agent_uses_richer_live_hotel_fields(): void
    {
        $this->setEnv('SERPAPI_KEY', 'serpapi-test-key');
        $this->setEnv('RAPIDAPI_KEY', '');
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake([
            'serpapi.com/search.json*' => Http::response([
                'properties' => [
                    [
                        'name' => 'Near Beach Hotel',
                        'overall_rating' => 4.6,
                        'reviews' => 321,
                        'rate_per_night' => ['extracted_lowest' => 900000],
                        'total_rate' => ['extracted_lowest' => 1800000],
                        'gps_coordinates' => ['latitude' => 16.0617, 'longitude' => 108.2468],
                        'nearby_places' => [['name' => 'My Khe']],
                        'images' => [['original_image' => 'https://img.example/near.jpg']],
                    ],
                ],
            ], 200),
        ]);

        $result = app(HotelAgent::class)->run(new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            departureDate: '2026-07-07',
            days: 3,
            budget: 8000000,
            travelers: 2,
            adults: 2,
        ));

        $this->assertSame('ok', $result->status);
        $this->assertSame('SerpAPI Google Hotels', $result->source);
        $this->assertSame('Near Beach Hotel', $result->recommendations[0]->title);
        $this->assertSame(1800000, $result->recommendations[0]->price);
        $this->assertSame('https://img.example/near.jpg', $result->recommendations[0]->imageUrl);
        $this->assertStringContainsString('Nguồn giá: Google Hotels live rate', $result->recommendations[0]->details);
    }

    public function test_hotel_agent_has_stable_local_fallback_for_snapshot_destinations(): void
    {
        $this->setEnv('SERPAPI_KEY', '');
        $this->setEnv('RAPIDAPI_KEY', '');
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake(['*' => Http::response([], 500)]);

        foreach ([
            'Nha Trang' => 'Vinpearl Deluxe',
            'Hue' => 'Hong Thien 2 hotel',
            'Ha Long' => 'Ha Long Pearl',
            'Phu Quoc' => 'Lahana Resort Phu Quoc',
            'Hoi An' => 'Hoi An Historic Hotel',
        ] as $destination => $expectedHotel) {
            $result = app(HotelAgent::class)->run(new UserRequest(
                destination: $destination,
                origin: 'Ha Noi',
                departureDate: '2026-05-10',
                days: 3,
                budget: 8000000,
                travelers: 1,
                adults: 1,
            ));

            $this->assertSame('ok', $result->status, $destination);
            $this->assertSame('Local fallback dataset', $result->source, $destination);
            $this->assertSame($expectedHotel, $result->recommendations[0]->title, $destination);
            $this->assertCount(4, $result->recommendations, $destination);
        }
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
