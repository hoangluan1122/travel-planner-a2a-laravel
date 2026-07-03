<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use App\TravelPlanner\Services\TravelDataRepository;

final class TransportAgent
{
    public string $name = 'transport-agent';

    public function __construct(
        private readonly TravelDataRepository $data,
        private readonly LiveTravelService $live,
    )
    {
    }

    public function run(UserRequest $request): AgentResult
    {
        [$rows, $debug] = $this->live->fetchLiveFlightsWithDebug(
            destination: $request->destination,
            adults: $request->travelers,
            maxPrice: $request->budget,
            origin: $request->origin,
            departureDate: $request->departureDate ?: null,
        );
        $source = 'SerpAPI Google Flights';
        $notes = ['Flight source active.', 'Flight debug: '.($debug['message'] ?? $debug['error'] ?? 'no message')];
        if ($rows === []) {
            $rows = $this->data->flights($request->destination);
            $source = 'Local fallback dataset';
            $notes = ['Live flight provider unavailable: '.($debug['error'] ?? 'empty result').'. Local fallback active.'];
        }

        $items = [];
        foreach ($rows as $row) {
            $price = (int) ($row['price'] ?? 0);
            $score = round(max(0, 100 - ($price / max($request->budget, 1)) * 100) / 20, 2);
            $title = sprintf('%s %s -> %s', $row['airline'] ?? 'Transport', $row['departure'] ?? '?', $row['arrival'] ?? '?');
            $items[] = new Recommendation(
                title: $title,
                details: 'Nguon: '.($row['source'] ?? $source).' | Origin: '.$request->origin.(! empty($row['departure_time']) ? ' | '.$row['departure_time'].' -> '.($row['arrival_time'] ?? '') : ''),
                price: $price,
                score: $score,
                reason: 'Gia thap hon va phu hop ngan sach duoc uu tien.',
            );
        }

        usort($items, fn (Recommendation $a, Recommendation $b): int => [$b->score, $a->price] <=> [$a->score, $b->price]);

        if ($items === []) {
            return new AgentResult($this->name, 'No transport data available.', [], $notes, $source, 'empty', ['flight_debug' => $debug]);
        }

        $items[0] = new Recommendation(
            title: '[De xuat chinh] '.$items[0]->title,
            details: $items[0]->details,
            price: $items[0]->price,
            score: $items[0]->score,
            reason: $items[0]->reason,
        );

        return new AgentResult($this->name, 'Found '.count($items).' transport options.', array_slice($items, 0, 4), $notes, $source, 'ok', ['flight_debug' => $debug]);
    }
}
