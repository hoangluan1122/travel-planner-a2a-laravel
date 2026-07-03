<?php

namespace App\TravelPlanner\DTO;

final class AgentResult
{
    /**
     * @param array<int, Recommendation> $recommendations
     */
    public function __construct(
        public string $agent,
        public string $summary,
        public array $recommendations = [],
        public array $notes = [],
        public string $source = 'local',
        public string $status = 'ok',
        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'agent' => $this->agent,
            'summary' => $this->summary,
            'recommendations' => array_map(fn (Recommendation $item) => $item->toArray(), $this->recommendations),
            'notes' => $this->notes,
            'source' => $this->source,
            'status' => $this->status,
            'extra' => $this->extra,
        ];
    }
}
