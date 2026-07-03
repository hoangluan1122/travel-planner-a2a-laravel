<?php

namespace App\TravelPlanner\DTO;

final class Recommendation
{
    public function __construct(
        public string $title,
        public string $details,
        public int $price = 0,
        public float $score = 0.0,
        public string $reason = '',
        public string $imageUrl = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'details' => $this->details,
            'price' => $this->price,
            'score' => $this->score,
            'reason' => $this->reason,
            'image_url' => $this->imageUrl,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            details: (string) ($data['details'] ?? ''),
            price: (int) ($data['price'] ?? 0),
            score: (float) ($data['score'] ?? 0),
            reason: (string) ($data['reason'] ?? ''),
            imageUrl: (string) ($data['image_url'] ?? ''),
        );
    }
}
