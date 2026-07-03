<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LocationResolver;

abstract class EstimatedGroundAdapter implements TransportProviderAdapter
{
    public function __construct(protected readonly LocationResolver $locations)
    {
    }

    protected function distanceKm(UserRequest $request): int
    {
        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);
        if (is_numeric($origin['lat'] ?? null) && is_numeric($origin['lon'] ?? null) && is_numeric($destination['lat'] ?? null) && is_numeric($destination['lon'] ?? null)) {
            $earth = 6371;
            $latDelta = deg2rad((float) $destination['lat'] - (float) $origin['lat']);
            $lonDelta = deg2rad((float) $destination['lon'] - (float) $origin['lon']);
            $a = sin($latDelta / 2) ** 2
                + cos(deg2rad((float) $origin['lat'])) * cos(deg2rad((float) $destination['lat'])) * sin($lonDelta / 2) ** 2;

            return max(20, (int) round(2 * $earth * asin(min(1, sqrt($a)))));
        }

        return 400;
    }

    protected function durationFromSpeed(int $distanceKm, int $speedKmh): string
    {
        $minutes = (int) ceil(($distanceKm / max(1, $speedKmh)) * 60);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
