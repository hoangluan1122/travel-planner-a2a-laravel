<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\Services\LocationResolver;

final class TransportValidator
{
    public function __construct(private readonly LocationResolver $locations)
    {
    }

    /**
     * @return array{0: bool, 1: array<int, string>}
     */
    public function validate(TransportOption $option, string $requestOrigin, string $requestDestination): array
    {
        $notes = [];
        $origin = $this->locations->resolve($requestOrigin);
        $destination = $this->locations->resolve($requestDestination);
        $departureText = $this->normalized($option->departure);
        $arrivalText = $this->normalized($option->arrival);
        $originName = $this->normalized($origin['canonical_name'] ?? '');
        $destinationName = $this->normalized($destination['canonical_name'] ?? '');
        $originBusHub = $this->normalized($origin['nearest_bus_hub'] ?? '');
        $destinationBusHub = $this->normalized($destination['nearest_bus_hub'] ?? '');

        $expectedOrigins = array_filter(array_unique([
            $originName,
            $this->normalized($origin['nearest_airport_hub'] ?? ''),
            $this->normalized($origin['nearest_train_hub'] ?? ''),
            $originBusHub,
            $this->normalized($origin['iata'] ?? ''),
        ]));
        $expectedDestinations = array_filter(array_unique([
            $destinationName,
            $this->normalized($destination['nearest_airport_hub'] ?? ''),
            $this->normalized($destination['nearest_train_hub'] ?? ''),
            $destinationBusHub,
            $this->normalized($destination['iata'] ?? ''),
        ]));

        if (! $this->containsAny($departureText, $expectedOrigins)) {
            $notes[] = 'departure-mismatch';
        }
        if (! $this->containsAny($arrivalText, $expectedDestinations)) {
            $notes[] = 'arrival-mismatch';
        }
        if ($option->price < 0) {
            $notes[] = 'negative-price';
        }
        if ($option->usesNearestHub && ! ($option->originHub || $option->destinationHub)) {
            $notes[] = 'nearest-hub-flag-missing-metadata';
        }

        if ($option->mode === 'bus') {
            $sameRealPlace = $originName === $destinationName;
            $sameDepartureArrival = $departureText === $arrivalText;
            $routeLoopsOnOriginHub = $originBusHub !== '' && str_starts_with($departureText, $originBusHub) && str_starts_with($arrivalText, $originBusHub);
            $routeLoopsOnDestinationHub = $destinationBusHub !== '' && str_starts_with($departureText, $destinationBusHub) && str_starts_with($arrivalText, $destinationBusHub);
            if (! $sameRealPlace && ($sameDepartureArrival || $routeLoopsOnOriginHub || $routeLoopsOnDestinationHub)) {
                $notes[] = 'self-loop-bus-route';
            }
        }

        return [count($notes) === 0, $notes];
    }

    /**
     * @param array<int, TransportOption> $options
     * @return array{0: array<int, TransportOption>, 1: array<int, string>}
     */
    public function filterOptions(array $options, string $requestOrigin, string $requestDestination): array
    {
        $accepted = [];
        $droppedNotes = [];
        $seen = [];

        foreach ($options as $option) {
            [$ok, $notes] = $this->validate($option, $requestOrigin, $requestDestination);
            if (! $ok) {
                $droppedNotes[] = sprintf('Dropped %s:%s:%s->%s because %s', $option->mode, $option->operator, $option->departure, $option->arrival, implode(', ', $notes));
                continue;
            }
            $signature = $option->signature();
            if (isset($seen[$signature])) {
                $droppedNotes[] = sprintf('Dropped duplicate %s:%s:%s->%s', $option->mode, $option->operator, $option->departure, $option->arrival);
                continue;
            }
            $seen[$signature] = true;
            $accepted[] = $option;
        }

        return [$accepted, $droppedNotes];
    }

    private function normalized(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
