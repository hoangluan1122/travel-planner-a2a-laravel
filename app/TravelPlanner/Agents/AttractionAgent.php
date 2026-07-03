<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use App\TravelPlanner\Services\TravelDataRepository;

final class AttractionAgent
{
    public string $name = 'attraction-agent';

    public function __construct(
        private readonly TravelDataRepository $data,
        private readonly LiveTravelService $live,
    )
    {
    }

    public function run(UserRequest $request, AgentResult $weather, array $hotelContext = []): AgentResult
    {
        $items = [];
        $rainy = str_contains(strtolower($weather->summary), 'rain') || str_contains(strtolower($weather->summary), 'mua');

        $rows = $this->live->fetchLiveAttractions($request->destination);
        $source = 'Geoapify';
        $notes = ['Attraction search strategy: live provider ranking.', 'Hotel context confidence: '.($hotelContext['confidence'] ?? 'none').'.'];
        if ($rows === []) {
            $rows = $this->data->attractions($request->destination);
            $source = 'Local fallback dataset';
            $notes = ['Attraction search strategy: local fallback ranking.', 'Hotel context confidence: '.($hotelContext['confidence'] ?? 'none').'.'];
        }

        foreach ($rows as $row) {
            $tags = array_map('strtolower', $row['interest_tags'] ?? []);
            $interestScore = $request->interests === [] ? 0.5 : min(count(array_intersect($tags, $request->interests)) / max(count(array_unique($request->interests)), 1), 1);
            $weatherScore = $rainy && (($row['type'] ?? 'outdoor') === 'outdoor') ? 0.2 : 0.9;
            $priceScore = ((int) ($row['cost'] ?? 0)) === 0 ? 0.4 : 0.0;
            $score = round($interestScore * 4 + $weatherScore * 2 + $priceScore + 1, 2);
            $items[] = new Recommendation(
                title: (string) ($row['name'] ?? 'Attraction'),
                details: sprintf('Type: %s | Area: %s | Tags: %s | Source: %s', $row['type'] ?? 'outdoor', $row['area'] ?? $hotelContext['area'] ?? $request->destination, implode(', ', $tags), $row['source'] ?? $source),
                price: (int) ($row['cost'] ?? 0),
                score: $score,
                reason: 'Phu hop so thich, boi canh luu tru va thoi tiet hien co.',
            );
        }

        usort($items, fn (Recommendation $a, Recommendation $b): int => [$b->score, $a->price] <=> [$a->score, $b->price]);

        if ($items === []) {
            return new AgentResult($this->name, 'No attraction data matched the requested activity.', [], $notes, $source, 'empty');
        }

        return new AgentResult(
            agent: $this->name,
            summary: 'Attractions ranked by interests, weather and hotel context.',
            recommendations: array_slice($items, 0, 6),
            notes: $notes,
            source: $source,
            status: 'ok',
            extra: ['debug' => ['hotel_context' => $hotelContext]],
        );
    }
}
