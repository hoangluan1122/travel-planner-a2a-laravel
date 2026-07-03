<?php

namespace Tests\Feature;

use App\TravelPlanner\Agents\AttractionAgent;
use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AttractionActivityParityTest extends TestCase
{
    public function test_activity_search_prefers_curated_beach_swimming_results(): void
    {
        Http::fake([
            '*' => Http::response(['features' => [], 'elements' => []], 200),
        ]);

        $rows = app(LiveTravelService::class)->fetchActivityAttractions('Da Nang', 'beach_swimming', [], 6);

        $this->assertNotEmpty($rows);
        $this->assertSame('My Khe Beach', $rows[0]['name']);
        $this->assertContains('swimming', $rows[0]['interest_tags']);
        $this->assertContains('beach', $rows[0]['interest_tags']);
        $this->assertSame('Curated Da Nang attractions', $rows[0]['source']);
    }

    public function test_attraction_agent_uses_activity_strategy_for_beach_interest(): void
    {
        Http::fake([
            '*' => Http::response(['features' => [], 'elements' => []], 200),
        ]);

        $request = new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            days: 3,
            budget: 8000000,
            interests: ['beach', 'swimming'],
            travelers: 2,
            adults: 2,
        );

        $result = app(AttractionAgent::class)->run(
            $request,
            new AgentResult('weather-agent', 'Thoi tiet kho rao.', [], [], 'test', 'ok'),
            ['confidence' => 'medium', 'area' => 'My Khe'],
        );

        $this->assertSame('ok', $result->status);
        $this->assertStringContainsString('beach_swimming', implode(' ', $result->notes));
        $this->assertSame('My Khe Beach', $result->recommendations[0]->title);
        $this->assertStringContainsString('Phù hợp', $result->recommendations[0]->details);
    }

    public function test_live_attractions_filter_geoapify_administrative_and_lodging_noise(): void
    {
        $this->setEnv('GEOAPIFY_API_KEY', 'geoapify-test-key');

        Http::fake([
            'api.geoapify.com/v2/places*' => Http::response([
                'features' => [
                    ['properties' => ['name' => 'Hội An (phường)', 'categories' => ['administrative']]],
                    ['properties' => ['name' => 'An Khê, Đà Nẵng', 'categories' => ['administrative']]],
                    ['properties' => ['name' => 'Beach Hotel Da Nang', 'categories' => ['accommodation.hotel']]],
                    ['properties' => ['name' => 'Bảo tàng Hội An', 'categories' => ['entertainment.museum']]],
                ],
            ], 200),
            '*' => Http::response(['query' => ['geosearch' => []], 'elements' => []], 200),
        ]);

        $rows = app(LiveTravelService::class)->fetchLiveAttractions('Hoi An', 6);

        $this->assertSame(['Bảo tàng Hội An'], array_column($rows, 'name'));
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
