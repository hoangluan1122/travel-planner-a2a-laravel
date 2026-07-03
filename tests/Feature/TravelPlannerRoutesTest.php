<?php

namespace Tests\Feature;

use Tests\TestCase;

final class TravelPlannerRoutesTest extends TestCase
{
    public function test_home_page_loads(): void
    {
        $this->get('/v2')
            ->assertOk()
            ->assertSee('Không gian lập kế hoạch AI cao cấp');
    }

    public function test_api_plan_returns_compatible_shape(): void
    {
        $this->postJson('/api/plan', [
            'user_text' => 'Toi muon di Da Nang 3 ngay ngan sach 8 trieu cho 2 nguoi thich bien',
            'origin' => 'SGN',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'parsed_request',
                'travel_plan' => [
                    'destination',
                    'origin',
                    'days',
                    'transport_options',
                    'hotels',
                    'attractions',
                    'daily_itinerary',
                    'estimated_cost',
                    'provider_status',
                ],
                'images',
            ]);
    }

    public function test_providers_status_returns_debug_payload(): void
    {
        $this->getJson('/api/providers-status?destination=Da%20Nang')
            ->assertOk()
            ->assertJsonStructure([
                'destination',
                'origin',
                'weather_key_present',
                'serpapi_key_present',
                'origin_iata_present',
                'geoapify_key_present',
                'hotels_count',
                'attractions_count',
                'flights_count',
                'hotels_preview',
                'attractions_preview',
                'flights_preview',
                'flight_debug',
            ]);
    }
}
