<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LocationResolver;

final class TransportStrategyFactory
{
    public function __construct(
        private readonly LocationResolver $locations,
        private readonly SerpApiFlightAdapter $flight,
        private readonly TrainProviderAdapter $train,
        private readonly BusProviderAdapter $bus,
        private readonly CarRouteProviderAdapter $car,
    ) {
    }

    public function create(UserRequest $request): MixedTransportStrategy
    {
        $distance = $this->estimateDistance($request->origin, $request->destination);
        $preferred = strtolower(trim($request->preferredTransport));

        if ($preferred === 'train') {
            return new MixedTransportStrategy([$this->train, $this->bus, $this->flight, $this->car]);
        }
        if ($preferred === 'flight') {
            return new MixedTransportStrategy([$this->flight, $this->train, $this->bus, $this->car]);
        }
        if ($distance > 700) {
            return new MixedTransportStrategy([$this->train, $this->flight, $this->bus, $this->car]);
        }
        if ($distance > 250) {
            return new MixedTransportStrategy([$this->train, $this->bus, $this->flight, $this->car]);
        }

        return new MixedTransportStrategy([$this->bus, $this->train, $this->car]);
    }

    public function estimateDistance(string $origin, string $destination): int
    {
        $originResolved = $this->locations->resolve($origin);
        $destinationResolved = $this->locations->resolve($destination);
        if (is_numeric($originResolved['lat'] ?? null) && is_numeric($originResolved['lon'] ?? null) && is_numeric($destinationResolved['lat'] ?? null) && is_numeric($destinationResolved['lon'] ?? null)) {
            $earth = 6371;
            $latDelta = deg2rad((float) $destinationResolved['lat'] - (float) $originResolved['lat']);
            $lonDelta = deg2rad((float) $destinationResolved['lon'] - (float) $originResolved['lon']);
            $a = sin($latDelta / 2) ** 2
                + cos(deg2rad((float) $originResolved['lat'])) * cos(deg2rad((float) $destinationResolved['lat'])) * sin($lonDelta / 2) ** 2;

            return (int) round(2 * $earth * asin(min(1, sqrt($a))));
        }

        $originName = (string) ($originResolved['canonical_name'] ?? $origin);
        $destinationName = (string) ($destinationResolved['canonical_name'] ?? $destination);
        $shortRoutes = [
            'Ho Chi Minh|Vung Tau', 'Vung Tau|Ho Chi Minh', 'Ho Chi Minh|Can Tho', 'Can Tho|Ho Chi Minh',
            'Da Nang|Hoi An', 'Hoi An|Da Nang', 'Da Nang|Hue', 'Hue|Da Nang',
            'Ha Noi|Ninh Binh', 'Ninh Binh|Ha Noi', 'Ha Noi|Ha Long', 'Ha Long|Ha Noi',
        ];
        $mediumRoutes = [
            'Ha Noi|Hue', 'Hue|Ha Noi', 'Ha Noi|Da Nang', 'Da Nang|Ha Noi',
            'Ho Chi Minh|Da Nang', 'Da Nang|Ho Chi Minh', 'Ha Noi|Da Lat', 'Da Lat|Ha Noi',
            'Ho Chi Minh|Da Lat', 'Da Lat|Ho Chi Minh',
        ];
        $key = "{$originName}|{$destinationName}";
        if (in_array($key, $shortRoutes, true)) {
            return 120;
        }
        if (in_array($key, $mediumRoutes, true)) {
            return 650;
        }

        return 400;
    }
}
