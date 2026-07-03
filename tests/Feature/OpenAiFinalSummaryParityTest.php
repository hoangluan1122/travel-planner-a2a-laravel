<?php

namespace Tests\Feature;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\RootTravelPlanner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiFinalSummaryParityTest extends TestCase
{
    public function test_english_request_uses_openai_final_summary_when_key_is_available(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'openai-test-key');
        $this->setEnv('OPENAI_MODEL', 'gpt-4o-mini');
        $this->setEnv('OPENAI_CHAT_COMPLETIONS_URL', 'https://api.openai.com/v1/chat/completions');
        $this->disableOtherProviderKeys();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'AI crafted English travel summary.']],
                ],
            ], 200),
            '*' => Http::response([], 500),
        ]);

        $plan = app(RootTravelPlanner::class)->run(new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            lang: 'en',
            days: 2,
            budget: 6000000,
            travelers: 2,
            adults: 2,
        ));

        $this->assertSame('AI crafted English travel summary.', $plan->finalRecommendation);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return (string) $request->url() === 'https://api.openai.com/v1/chat/completions'
                && ($body['model'] ?? '') === 'gpt-4o-mini'
                && ($body['messages'][0]['role'] ?? '') === 'system'
                && str_contains($body['messages'][1]['content'] ?? '', 'Write a concise premium travel planner summary in English');
        });
    }

    public function test_english_request_falls_back_without_openai_key(): void
    {
        $this->setEnv('OPENAI_API_KEY', '');
        $this->disableOtherProviderKeys();

        Http::fake(['*' => Http::response([], 500)]);

        $plan = app(RootTravelPlanner::class)->run(new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            lang: 'en',
            days: 2,
            budget: 6000000,
            travelers: 2,
            adults: 2,
        ));

        $this->assertStringContainsString('Route:', $plan->finalRecommendation);
        $this->assertStringContainsString('Suggested transport:', $plan->finalRecommendation);
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'api.openai.com'));
    }

    public function test_vietnamese_request_does_not_call_openai_even_when_key_exists(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'openai-test-key');
        $this->disableOtherProviderKeys();

        Http::fake(['*' => Http::response([], 500)]);

        $plan = app(RootTravelPlanner::class)->run(new UserRequest(
            destination: 'Da Nang',
            origin: 'Ha Noi',
            lang: 'vi',
            days: 2,
            budget: 6000000,
            travelers: 2,
            adults: 2,
        ));

        $this->assertStringContainsString('Tuyến đi:', $plan->finalRecommendation);
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'api.openai.com'));
    }

    private function disableOtherProviderKeys(): void
    {
        foreach (['OPENWEATHER_API_KEY', 'SERPAPI_KEY', 'RAPIDAPI_KEY', 'GEOAPIFY_API_KEY', 'PEXELS_API_KEY'] as $name) {
            $this->setEnv($name, '');
        }
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
