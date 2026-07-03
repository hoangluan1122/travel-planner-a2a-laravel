<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use App\TravelPlanner\Services\LocationResolver;

final class SerpApiFlightAdapter implements TransportProviderAdapter
{
    public function __construct(
        private readonly LiveTravelService $live,
        private readonly LocationResolver $locations,
    ) {
    }

    public function search(UserRequest $request): array
    {
        $rows = $this->live->fetchLiveFlights(
            destination: $request->destination,
            adults: $request->travelers,
            maxPrice: null,
            origin: $request->origin,
            departureDate: $request->departureDate ?: null,
        );
        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);

        return array_map(function (array $row) use ($origin, $destination): TransportOption {
            $operator = (string) ($row['airline'] ?? 'Flight');
            $departure = (string) ($row['departure'] ?? ($origin['iata'] ?? $origin['canonical_name']));
            $arrival = (string) ($row['arrival'] ?? ($destination['iata'] ?? $destination['canonical_name']));

            return new TransportOption(
                mode: 'flight',
                provider: 'SerpAPI Google Flights',
                operator: $operator,
                departure: $departure,
                arrival: $arrival,
                price: (int) ($row['price'] ?? 0),
                duration: $this->minutesToDuration((int) ($row['duration_minutes'] ?? 0)),
                reason: 'Kết quả vé máy bay live từ SerpAPI Google Flights.',
            );
        }, $rows);
    }

    private function minutesToDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
