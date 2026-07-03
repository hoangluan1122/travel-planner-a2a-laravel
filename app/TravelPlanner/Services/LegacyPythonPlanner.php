<?php

namespace App\TravelPlanner\Services;

use App\TravelPlanner\DTO\TravelPlan;
use Illuminate\Support\Str;

final class LegacyPythonPlanner
{
    public function __construct(private readonly VietnameseTextPolisher $polisher)
    {
    }

    public function enabled(): bool
    {
        return filter_var(env('USE_LEGACY_PYTHON_PLANNER', true), FILTER_VALIDATE_BOOL);
    }

    public function run(string $userText, ?string $origin = null, ?string $departureDate = null, ?string $lang = 'vi'): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $python = (string) env('LEGACY_PYTHON', '');
        $projectPath = (string) env('LEGACY_TRAVEL_PLANNER_PATH', '');
        $script = base_path('scripts/legacy_plan.py');
        if ($python === '' || $projectPath === '' || ! is_file($python) || ! is_dir($projectPath) || ! is_file($script)) {
            return null;
        }

        $payload = json_encode([
            'project_path' => $projectPath,
            'user_text' => $userText,
            'origin' => $origin,
            'departure_date' => $departureDate,
            'lang' => $lang,
            'env' => [
                'OPENWEATHER_API_KEY' => env('OPENWEATHER_API_KEY'),
                'SERPAPI_KEY' => env('SERPAPI_KEY'),
                'ORIGIN_IATA' => env('ORIGIN_IATA'),
                'GEOAPIFY_API_KEY' => env('GEOAPIFY_API_KEY'),
                'PEXELS_API_KEY' => env('PEXELS_API_KEY'),
                'RAPIDAPI_KEY' => env('RAPIDAPI_KEY'),
                'RAPIDAPI_HOST' => env('RAPIDAPI_HOST'),
            ],
        ], JSON_UNESCAPED_UNICODE);

        $payloadPath = storage_path('app/legacy-python-request-'.Str::uuid().'.json');
        file_put_contents($payloadPath, $payload ?: '{}');

        try {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open([$python, $script, $payloadPath], $descriptorSpec, $pipes, base_path());
            if (! is_resource($process)) {
                return null;
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            if (is_file($payloadPath)) {
                @unlink($payloadPath);
            }
        }

        if ($exitCode !== 0) {
            report(new \RuntimeException('Legacy Python planner failed: '.$stderr));
            return null;
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded) || ! isset($decoded['travel_plan'])) {
            report(new \RuntimeException('Legacy Python planner returned invalid JSON: '.$stdout));
            return null;
        }

        return $this->polisher->polishPayload($decoded);
    }

    public function runPlan(string $userText, ?string $origin = null, ?string $departureDate = null, ?string $lang = 'vi'): ?TravelPlan
    {
        $payload = $this->run($userText, $origin, $departureDate, $lang);
        if (! $payload) {
            return null;
        }

        return TravelPlan::fromArray($payload['travel_plan']);
    }
}
