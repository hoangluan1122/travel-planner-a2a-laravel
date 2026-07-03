<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SnapshotCommandParityTest extends TestCase
{
    public function test_stability_snapshot_command_writes_python_like_payload(): void
    {
        $this->disableProviderKeys();
        Http::fake(['*' => Http::response([], 500)]);

        $output = storage_path('framework/testing/stability_batch_latest.json');
        File::delete($output);

        $this->artisan('travel-planner:snapshot', [
            '--kind' => 'stability',
            '--limit' => 2,
            '--output' => $output,
        ])->assertExitCode(0);

        $payload = json_decode(File::get($output), true);
        $this->assertSame(2, $payload['summary']['total_cases']);
        $this->assertCount(2, $payload['cases']);
        $this->assertSame('Da Lat', $payload['cases'][0]['destination']);
        $this->assertArrayHasKey('provider_status', $payload['cases'][0]);
        $this->assertArrayHasKey('transport_titles', $payload['cases'][0]);
        $this->assertArrayHasKey('hotel_titles', $payload['cases'][0]);
        $this->assertArrayHasKey('attraction_titles', $payload['cases'][0]);
        $this->assertArrayHasKey('estimated_cost', $payload['cases'][0]);
    }

    public function test_ten_case_snapshot_command_writes_row_array_like_python_script(): void
    {
        $this->disableProviderKeys();
        Http::fake(['*' => Http::response([], 500)]);

        $output = storage_path('framework/testing/ten_case_snapshot.json');
        File::delete($output);

        $this->artisan('travel-planner:snapshot', [
            '--kind' => 'ten-case',
            '--limit' => 1,
            '--output' => $output,
        ])->assertExitCode(0);

        $payload = json_decode(File::get($output), true);
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('summary', $payload);
        $this->assertSame('Ha Noi', $payload[0]['destination']);
        $this->assertArrayHasKey('provider_status', $payload[0]);
    }

    public function test_compare_snapshot_command_reports_mismatches(): void
    {
        $python = storage_path('framework/testing/python_snapshot.json');
        $laravel = storage_path('framework/testing/laravel_snapshot.json');
        $output = storage_path('framework/testing/snapshot_compare_latest.json');
        File::ensureDirectoryExists(dirname($python));
        File::put($python, json_encode([
            'summary' => ['total_cases' => 2],
            'cases' => [
                [
                    'destination' => 'Da Nang',
                    'transport_count' => 1,
                    'hotel_count' => 1,
                    'attraction_count' => 1,
                    'transport_titles' => ['Flight A'],
                    'hotel_titles' => ['Hotel A'],
                    'attraction_titles' => ['Beach A'],
                    'estimated_cost' => 1000000,
                    'provider_status' => [
                        'transport' => ['status' => 'ok'],
                        'hotels' => ['status' => 'ok'],
                        'attractions' => ['status' => 'ok'],
                    ],
                ],
                [
                    'destination' => 'Hue',
                    'transport_count' => 2,
                    'hotel_count' => 1,
                    'attraction_count' => 1,
                    'transport_titles' => ['Train A'],
                    'hotel_titles' => ['Hotel B'],
                    'attraction_titles' => ['Citadel'],
                    'estimated_cost' => 2000000,
                    'provider_status' => [
                        'transport' => ['status' => 'ok'],
                        'hotels' => ['status' => 'ok'],
                        'attractions' => ['status' => 'ok'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
        File::put($laravel, json_encode([
            [
                'destination' => 'Da Nang',
                'transport_count' => 1,
                'hotel_count' => 1,
                'attraction_count' => 1,
                'transport' => ['Flight A'],
                'hotels' => ['Hotel A'],
                'attractions' => ['Beach A'],
                'estimated_cost' => 1100000,
                'provider_status' => [
                    'transport' => ['status' => 'ok'],
                    'hotels' => ['status' => 'ok'],
                    'attractions' => ['status' => 'ok'],
                ],
            ],
            [
                'destination' => 'Hue',
                'transport_count' => 0,
                'hotel_count' => 1,
                'attraction_count' => 1,
                'transport' => [],
                'hotels' => ['Different Hotel'],
                'attractions' => ['Citadel'],
                'estimated_cost' => 3000000,
                'provider_status' => [
                    'transport' => ['status' => 'empty'],
                    'hotels' => ['status' => 'ok'],
                    'attractions' => ['status' => 'ok'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('travel-planner:compare-snapshot', [
            'python' => $python,
            'laravel' => $laravel,
            '--output' => $output,
        ])->assertExitCode(0);

        $report = json_decode(File::get($output), true);
        $this->assertSame(2, $report['summary']['total_python_cases']);
        $this->assertSame(2, $report['summary']['matched_cases']);
        $this->assertSame(1, $report['summary']['clean_cases']);
        $this->assertContains('transport_count_mismatch', $report['cases'][1]['issues']);
        $this->assertContains('transport_status_mismatch', $report['cases'][1]['issues']);
        $this->assertContains('hotel_title_no_overlap', $report['cases'][1]['issues']);
        $this->assertSame(1, $report['cases'][0]['transport']['title_overlap']);
    }

    private function disableProviderKeys(): void
    {
        foreach (['OPENWEATHER_API_KEY', 'SERPAPI_KEY', 'RAPIDAPI_KEY', 'GEOAPIFY_API_KEY', 'PEXELS_API_KEY', 'OPENAI_API_KEY'] as $name) {
            putenv($name.'=');
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
    }
}
