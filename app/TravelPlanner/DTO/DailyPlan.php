<?php

namespace App\TravelPlanner\DTO;

final class DailyPlan
{
    public function __construct(
        public int $day,
        public string $title,
        public string $morning,
        public string $afternoon,
        public string $evening,
        public int $estimatedCost = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'title' => $this->title,
            'morning' => $this->morning,
            'afternoon' => $this->afternoon,
            'evening' => $this->evening,
            'estimated_cost' => $this->estimatedCost,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            day: (int) ($data['day'] ?? 1),
            title: (string) ($data['title'] ?? ''),
            morning: (string) ($data['morning'] ?? ''),
            afternoon: (string) ($data['afternoon'] ?? ''),
            evening: (string) ($data['evening'] ?? ''),
            estimatedCost: (int) ($data['estimated_cost'] ?? 0),
        );
    }
}
