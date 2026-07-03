<?php

namespace App\TravelPlanner\Transport;

final class TransportOption
{
    public function __construct(
        public string $mode,
        public string $provider,
        public string $operator,
        public string $departure,
        public string $arrival,
        public int $price = 0,
        public string $duration = '',
        public float $score = 0.0,
        public string $reason = '',
        public string $tag = '',
        public bool $usesNearestHub = false,
        public ?string $originHub = null,
        public ?string $destinationHub = null,
        public bool $priceVerified = true,
        public string $fareLabel = '',
    ) {
    }

    public function signature(): string
    {
        return implode('|', [
            $this->mode,
            strtolower(trim($this->operator)),
            strtolower(trim($this->departure)),
            strtolower(trim($this->arrival)),
            $this->price,
        ]);
    }
}
