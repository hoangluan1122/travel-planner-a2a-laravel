<?php

namespace App\TravelPlanner\DTO;

final class TravelPlan
{
    /**
     * @param array<int, Recommendation> $transportOptions
     * @param array<int, Recommendation> $hotels
     * @param array<int, Recommendation> $attractions
     * @param array<int, DailyPlan> $dailyItinerary
     */
    public function __construct(
        public string $destination,
        public string $origin,
        public int $days,
        public string $weatherSummary,
        public array $weatherExtra,
        public array $transportOptions,
        public array $hotels,
        public array $attractions,
        public array $dailyItinerary,
        public int $estimatedCost,
        public string $finalRecommendation,
        public array $providerStatus = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'destination' => $this->destination,
            'origin' => $this->origin,
            'days' => $this->days,
            'weather_summary' => $this->weatherSummary,
            'weather_extra' => $this->weatherExtra,
            'transport_options' => array_map(fn (Recommendation $item) => $item->toArray(), $this->transportOptions),
            'hotels' => array_map(fn (Recommendation $item) => $item->toArray(), $this->hotels),
            'attractions' => array_map(fn (Recommendation $item) => $item->toArray(), $this->attractions),
            'daily_itinerary' => array_map(fn (DailyPlan $item) => $item->toArray(), $this->dailyItinerary),
            'estimated_cost' => $this->estimatedCost,
            'final_recommendation' => $this->finalRecommendation,
            'provider_status' => $this->providerStatus,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            destination: (string) ($data['destination'] ?? ''),
            origin: (string) ($data['origin'] ?? ''),
            days: (int) ($data['days'] ?? 1),
            weatherSummary: (string) ($data['weather_summary'] ?? ''),
            weatherExtra: $data['weather_extra'] ?? [],
            transportOptions: array_map(fn (array $item): Recommendation => Recommendation::fromArray($item), $data['transport_options'] ?? []),
            hotels: array_map(fn (array $item): Recommendation => Recommendation::fromArray($item), $data['hotels'] ?? []),
            attractions: array_map(fn (array $item): Recommendation => Recommendation::fromArray($item), $data['attractions'] ?? []),
            dailyItinerary: array_map(fn (array $item): DailyPlan => DailyPlan::fromArray($item), $data['daily_itinerary'] ?? []),
            estimatedCost: (int) ($data['estimated_cost'] ?? 0),
            finalRecommendation: (string) ($data['final_recommendation'] ?? ''),
            providerStatus: $data['provider_status'] ?? [],
        );
    }
}
