<?php

namespace Tests\Feature;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\Agents\TransportAgent;
use App\TravelPlanner\Services\RootTravelPlanner;
use App\TravelPlanner\Services\TravelAdvisor;
use App\TravelPlanner\Transport\TransportOption;
use App\TravelPlanner\Transport\TransportStrategyFactory;
use App\TravelPlanner\Transport\TransportValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NativeTransportStrategyTest extends TestCase
{
    public function test_factory_prefers_ground_transport_for_nearby_routes(): void
    {
        $request = new UserRequest(
            destination: 'Ha Long',
            origin: 'Ha Noi',
            days: 2,
            budget: 5000000,
            adults: 2,
        );

        $options = app(TransportStrategyFactory::class)->create($request)->getOptions($request);

        $this->assertNotEmpty($options);
        $this->assertSame('bus', $options[0]->mode);
        $this->assertStringContainsString('Ha Noi', $options[0]->departure);
        $this->assertStringContainsString('Ha Long', $options[0]->arrival);
    }

    public function test_validator_drops_self_loop_bus_route(): void
    {
        $validator = app(TransportValidator::class);
        [$accepted, $notes] = $validator->filterOptions([
            new TransportOption(
                mode: 'bus',
                provider: 'test',
                operator: 'Loop Bus',
                departure: 'Ha Noi',
                arrival: 'Ha Noi',
                price: 100000,
            ),
        ], 'Ha Noi', 'Ha Long');

        $this->assertSame([], $accepted);
        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('self-loop-bus-route', $notes[0]);
    }

    public function test_preferred_train_is_promoted(): void
    {
        $request = new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            preferredTransport: 'train',
            days: 3,
            budget: 8000000,
            adults: 2,
        );

        $options = app(TransportStrategyFactory::class)->create($request)->getOptions($request);

        $this->assertNotEmpty($options);
        $this->assertSame('train', $options[0]->mode);
    }

    public function test_transport_agent_does_not_mix_local_fallback_when_native_option_exists(): void
    {
        $this->setEnv('SERPAPI_KEY', '');

        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [[
                    'distance' => 1530000,
                    'duration' => 168000,
                ]],
            ], 200),
            '*' => Http::response([], 500),
        ]);

        $result = app(TransportAgent::class)->run(new UserRequest(
            destination: 'Da Lat',
            origin: 'Ha Noi',
            days: 4,
            budget: 8000000,
            adults: 1,
        ));

        $this->assertSame('ok', $result->status);
        $this->assertStringContainsString('Ô tô', $result->recommendations[0]->title);
        $this->assertStringNotContainsString('Local fallback dataset', $result->source);
        $this->assertStringNotContainsString('fallback transport choices', implode(' ', $result->notes));
    }

    public function test_root_planner_exposes_native_advisor_status(): void
    {
        $request = new UserRequest(
            destination: 'Ha Long',
            origin: 'Ha Noi',
            days: 2,
            budget: 5000000,
            adults: 2,
        );

        $plan = app(RootTravelPlanner::class)->run($request)->toArray();

        $this->assertArrayHasKey('advisor', $plan['provider_status']);
        $this->assertSame('Laravel TravelAdvisor policy', $plan['provider_status']['advisor']['source']);
        $this->assertArrayHasKey('agent_reviews', $plan['provider_status']['advisor']);
        $this->assertNotEmpty($plan['final_recommendation']);
    }

    public function test_advisor_keeps_train_before_flight_when_train_is_preferred(): void
    {
        $request = new UserRequest(
            destination: 'Ho Chi Minh',
            origin: 'Ha Noi',
            departureDate: '2026-06-18',
            preferredTransport: 'train',
            days: 2,
            budget: 12000000,
            adults: 1,
        );
        $train = new Recommendation(
            title: '[Đề xuất chính] SE1 Ha Noi -> Ho Chi Minh',
            details: 'Tau hoa | Nguon: DSVN API | 34h 15m | gia tham khao',
            price: 1200000,
            score: 5.0,
            reason: 'Uu tien tau hoa theo yeu cau cua khach.',
        );
        $flight = new Recommendation(
            title: 'VN123 HAN -> SGN',
            details: 'Flight | Nguon: SerpAPI Google Flights | 2h 10m',
            price: 1600000,
            score: 7.0,
            reason: 'Ket qua chuyen bay live.',
        );

        $advised = app(TravelAdvisor::class)->adviseTransport($request, [$flight, $train]);

        $this->assertStringContainsString('SE1', $advised[0]->title);
        $this->assertStringContainsString('Tau hoa', $advised[0]->details);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
