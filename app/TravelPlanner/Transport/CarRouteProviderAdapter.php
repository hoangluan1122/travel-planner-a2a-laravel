<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use Illuminate\Support\Facades\Http;

final class CarRouteProviderAdapter extends EstimatedGroundAdapter
{
    public function search(UserRequest $request): array
    {
        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);
        $live = $this->liveSearch($origin, $destination);
        if ($live !== null) {
            return [$live];
        }

        $distance = $this->distanceKm($request);
        if ($distance > 450) {
            return [];
        }

        $price = max(450000, (int) round($distance * 9000));

        return [
            new TransportOption(
                mode: 'car',
                provider: 'Road transfer estimated adapter',
                operator: 'Ô tô riêng',
                departure: (string) $origin['canonical_name'],
                arrival: (string) $destination['canonical_name'],
                price: $price,
                duration: $this->durationFromSpeed($distance, 55),
                reason: 'Ước tính ô tô theo khoảng cách đường bộ; sẽ thay bằng OSRM route provider khi adapter OSRM được port đầy đủ.',
                priceVerified: false,
                fareLabel: 'giá ước tính',
            ),
        ];
    }

    private function liveSearch(array $origin, array $destination): ?TransportOption
    {
        if (! is_numeric($origin['lat'] ?? null) || ! is_numeric($origin['lon'] ?? null) || ! is_numeric($destination['lat'] ?? null) || ! is_numeric($destination['lon'] ?? null)) {
            return null;
        }

        $coords = "{$origin['lon']},{$origin['lat']};{$destination['lon']},{$destination['lat']}";
        try {
            $data = Http::timeout(18)
                ->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])
                ->get("https://router.project-osrm.org/route/v1/driving/{$coords}", [
                    'overview' => 'false',
                    'alternatives' => 'false',
                    'steps' => 'false',
                ])
                ->throw()
                ->json() ?: [];
        } catch (\Throwable) {
            return null;
        }

        $route = $data['routes'][0] ?? null;
        if (! is_array($route)) {
            return null;
        }
        $distanceKm = ((float) ($route['distance'] ?? 0)) / 1000;
        $durationMinutes = ((float) ($route['duration'] ?? 0)) / 60;
        if ($distanceKm <= 0 || $durationMinutes <= 0) {
            return null;
        }

        return new TransportOption(
            mode: 'car',
            provider: 'OSRM road routing',
            operator: 'Ô tô riêng',
            departure: (string) $origin['canonical_name'],
            arrival: (string) $destination['canonical_name'],
            price: $this->estimatePrice($distanceKm, $durationMinutes),
            duration: $this->minutesToDuration((int) round($durationMinutes)),
            reason: sprintf('Live road route từ OSRM. Quãng đường ước tính %.0f km.', $distanceKm),
        );
    }

    private function estimatePrice(float $distanceKm, float $durationMinutes): int
    {
        return max(150000, (int) round($distanceKm * 11000 + max($durationMinutes - 60, 0) * 1200));
    }

    private function minutesToDuration(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
