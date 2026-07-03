<?php

namespace App\TravelPlanner\DTO;

final class UserRequest
{
    public function __construct(
        public string $destination,
        public string $origin = 'SGN',
        public string $lang = 'vi',
        public string $departureDate = '',
        public string $preferredTransport = '',
        public int $days = 3,
        public int $budget = 8000000,
        public array $interests = [],
        public int $travelers = 1,
        public int $adults = 1,
        public int $children = 0,
        public array $childAges = [],
    ) {
        $this->days = max(1, min(14, $this->days));
        $this->budget = max(1, $this->budget);
        $this->adults = max(1, min(10, $this->adults));
        $this->children = max(0, min(10, $this->children));
        $this->travelers = max(1, min(10, $this->adults + $this->children));
        $this->childAges = array_slice(array_map('intval', $this->childAges), 0, $this->children);
        while (count($this->childAges) < $this->children) {
            $this->childAges[] = 7;
        }
    }

    public function toArray(): array
    {
        return [
            'destination' => $this->destination,
            'origin' => $this->origin,
            'lang' => $this->lang,
            'departure_date' => $this->departureDate,
            'preferred_transport' => $this->preferredTransport,
            'days' => $this->days,
            'budget' => $this->budget,
            'interests' => $this->interests,
            'travelers' => $this->travelers,
            'adults' => $this->adults,
            'children' => $this->children,
            'child_ages' => $this->childAges,
        ];
    }
}
