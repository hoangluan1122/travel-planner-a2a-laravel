<?php

namespace App\TravelPlanner\Transport;

final class TransportResult
{
    /**
     * @param array<int, TransportOption> $options
     * @param array<int, string> $notes
     */
    public function __construct(
        public string $selectedStrategy,
        public array $options = [],
        public array $notes = [],
    ) {
    }
}
