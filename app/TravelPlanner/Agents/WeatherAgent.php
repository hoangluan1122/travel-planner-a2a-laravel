<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LocationResolver;
use Illuminate\Support\Facades\Http;

final class WeatherAgent
{
    public string $name = 'weather-agent';

    public function __construct(private readonly LocationResolver $locations)
    {
    }

    public function run(UserRequest $request): AgentResult
    {
        $key = $this->secret('OPENWEATHER_API_KEY');
        if (! $key) {
            return new AgentResult(
                agent: $this->name,
                summary: 'Chưa có dữ liệu thời tiết live; dùng khuyến nghị linh hoạt theo mùa.',
                recommendations: [],
                notes: ['OPENWEATHER_API_KEY is missing.'],
                source: 'OpenWeather',
                status: 'empty',
                extra: ['forecast' => []],
            );
        }

        try {
            $resolved = $this->locations->resolve($request->destination);
            $params = [
                'appid' => $key,
                'units' => 'metric',
                'lang' => $request->lang,
            ];
            if (is_numeric($resolved['lat'] ?? null) && is_numeric($resolved['lon'] ?? null)) {
                $params['lat'] = (float) $resolved['lat'];
                $params['lon'] = (float) $resolved['lon'];
            } else {
                $params['q'] = $request->destination;
            }

            $current = Http::timeout(8)->get('https://api.openweathermap.org/data/2.5/weather', [
                ...$params,
            ])->throw()->json();
            $forecastResponse = Http::timeout(8)->get('https://api.openweathermap.org/data/2.5/forecast', [
                ...$params,
                'cnt' => min(max($request->days, 1) * 8, 40),
            ])->throw()->json();

            $weather = $current['weather'][0] ?? [];
            $main = $current['main'] ?? [];
            $forecast = $this->dailyForecast((array) ($forecastResponse['list'] ?? []), max($request->days, 1));
            $summary = sprintf(
                'Thời tiết hiện tại: %s. Nhiệt độ khoảng %s°C, cảm giác như %s°C.%s',
                $weather['description'] ?? 'không có mô tả',
                $main['temp'] ?? '?',
                $main['feels_like'] ?? '?',
                $forecast !== [] ? ' Dự báo: '.$this->forecastSummary($forecast) : '',
            );

            return new AgentResult(
                agent: $this->name,
                summary: $summary,
                recommendations: [new Recommendation('Live weather', $summary, reason: 'Used for trip timing.')],
                notes: [
                    'Độ ẩm: '.($main['humidity'] ?? '?').'%',
                    'Nguồn live OpenWeather current + forecast.',
                ],
                source: 'OpenWeather',
                status: 'ok',
                extra: ['current' => $current, 'forecast' => $forecast],
            );
        } catch (\Throwable $exception) {
            return new AgentResult(
                agent: $this->name,
                summary: 'Không lấy được thời tiết live; giữ lịch trình có phương án trong nhà.',
                recommendations: [],
                notes: ['OpenWeather error: '.$exception->getMessage()],
                source: 'OpenWeather',
                status: 'empty',
                extra: ['forecast' => []],
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dailyForecast(array $rows, int $days): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $date = substr((string) ($row['dt_txt'] ?? ''), 0, 10);
            if ($date === '') {
                continue;
            }
            $grouped[$date][] = $row;
        }

        $forecast = [];
        foreach (array_slice($grouped, 0, $days, true) as $date => $items) {
            $temps = array_values(array_filter(array_map(fn (array $item): ?float => is_numeric(data_get($item, 'main.temp')) ? (float) data_get($item, 'main.temp') : null, $items), fn (?float $value): bool => $value !== null));
            $humidity = array_values(array_filter(array_map(fn (array $item): ?int => is_numeric(data_get($item, 'main.humidity')) ? (int) data_get($item, 'main.humidity') : null, $items), fn (?int $value): bool => $value !== null));
            $descriptions = array_values(array_filter(array_map(fn (array $item): string => (string) data_get($item, 'weather.0.description', ''), $items)));
            $rainMm = array_sum(array_map(fn (array $item): float => (float) (data_get($item, 'rain.3h') ?? 0), $items));
            $pop = max(array_map(fn (array $item): float => (float) ($item['pop'] ?? 0), $items) ?: [0]);

            $forecast[] = [
                'date' => $date,
                'description' => $this->mostCommon($descriptions) ?: 'không có mô tả',
                'temp_min' => $temps !== [] ? round(min($temps), 1) : null,
                'temp_max' => $temps !== [] ? round(max($temps), 1) : null,
                'humidity_avg' => $humidity !== [] ? (int) round(array_sum($humidity) / count($humidity)) : null,
                'rain_mm' => round($rainMm, 1),
                'rain_probability' => round($pop * 100),
            ];
        }

        return $forecast;
    }

    /**
     * @param array<int, string> $values
     */
    private function mostCommon(array $values): string
    {
        $counts = [];
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);

        return array_key_first($counts) ?: '';
    }

    /**
     * @param array<int, array<string, mixed>> $forecast
     */
    private function forecastSummary(array $forecast): string
    {
        $parts = [];
        foreach (array_slice($forecast, 0, 3) as $day) {
            $parts[] = sprintf(
                '%s %s, %s-%s°C, mưa %s%%',
                $day['date'],
                $day['description'],
                $day['temp_min'] ?? '?',
                $day['temp_max'] ?? '?',
                $day['rain_probability'] ?? 0,
            );
        }

        return implode('; ', $parts).'.';
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
