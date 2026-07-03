<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use Illuminate\Support\Facades\Http;

final class WeatherAgent
{
    public string $name = 'weather-agent';

    public function run(UserRequest $request): AgentResult
    {
        $key = env('OPENWEATHER_API_KEY');
        if (! $key) {
            return new AgentResult(
                agent: $this->name,
                summary: 'Chua co du lieu thoi tiet live; dung khuyen nghi linh hoat theo mua.',
                recommendations: [],
                notes: ['OPENWEATHER_API_KEY is missing.'],
                source: 'OpenWeather',
                status: 'empty',
                extra: ['forecast' => []],
            );
        }

        try {
            $response = Http::timeout(6)->get('https://api.openweathermap.org/data/2.5/weather', [
                'q' => $request->destination,
                'appid' => $key,
                'units' => 'metric',
                'lang' => $request->lang,
            ])->throw()->json();

            $weather = $response['weather'][0] ?? [];
            $main = $response['main'] ?? [];
            $summary = sprintf(
                'Thoi tiet: %s. Nhiet do khoang %s°C, cam giac nhu %s°C.',
                $weather['description'] ?? 'khong co mo ta',
                $main['temp'] ?? '?',
                $main['feels_like'] ?? '?',
            );

            return new AgentResult(
                agent: $this->name,
                summary: $summary,
                recommendations: [new Recommendation('Live weather', $summary, reason: 'Used for trip timing.')],
                notes: [
                    'Do am: '.($main['humidity'] ?? '?').'%',
                    'Nguon live OpenWeather.',
                ],
                source: 'OpenWeather',
                status: 'ok',
                extra: ['current' => $response, 'forecast' => []],
            );
        } catch (\Throwable $exception) {
            return new AgentResult(
                agent: $this->name,
                summary: 'Khong lay duoc thoi tiet live; giu lich trinh co phuong an trong nha.',
                recommendations: [],
                notes: ['OpenWeather error: '.$exception->getMessage()],
                source: 'OpenWeather',
                status: 'empty',
                extra: ['forecast' => []],
            );
        }
    }
}
