<?php

namespace Tests\Feature;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\RootTravelPlanner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RootPlannerOrchestrationParityTest extends TestCase
{
    public function test_root_planner_reports_python_like_core_agent_orchestration(): void
    {
        $this->setEnv('OPENWEATHER_API_KEY', '');
        $this->setEnv('SERPAPI_KEY', '');
        $this->setEnv('RAPIDAPI_KEY', '');
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $plan = app(RootTravelPlanner::class)->run(new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            lang: 'vi',
            days: 3,
            budget: 8000000,
            interests: ['beach'],
            travelers: 2,
            adults: 2,
        ));

        $orchestration = data_get($plan->providerStatus, 'debug.orchestration');
        $this->assertSame('sync', $orchestration['mode']);
        $this->assertSame('sync', $orchestration['driver']);
        $this->assertSame(['weather-agent', 'transport-agent', 'hotel-agent'], $orchestration['agents']);
        $this->assertFalse($orchestration['fallback']);
        $this->assertIsInt($orchestration['duration_ms']);

        $this->assertArrayHasKey('resolved_origin', $plan->providerStatus['debug']);
        $this->assertArrayHasKey('resolved_destination', $plan->providerStatus['debug']);
        $this->assertArrayHasKey('transport_titles', $plan->providerStatus['debug']);
        $this->assertArrayHasKey('hotel_titles', $plan->providerStatus['debug']);
        $this->assertArrayHasKey('attraction_titles', $plan->providerStatus['debug']);
        $this->assertArrayHasKey('attraction_debug', $plan->providerStatus['debug']);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
