<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\LiveTravelService;
use App\TravelPlanner\Services\TravelDataRepository;

final class HotelAgent
{
    public string $name = 'hotel-agent';

    public function __construct(
        private readonly TravelDataRepository $data,
        private readonly LiveTravelService $live,
    )
    {
    }

    public function run(UserRequest $request): AgentResult
    {
        $nights = max($request->days - 1, 1);
        $rooms = max(1, (int) ceil($request->adults / 2));
        $items = [];

        $rows = $this->live->fetchLiveHotels($request->destination);
        $source = 'Geoapify';
        $notes = ["Hotel search used {$rooms} room(s) for {$request->adults} adult(s) and {$request->children} child(ren)."];
        if ($rows === []) {
            $rows = $this->data->hotels($request->destination);
            $source = 'Local fallback dataset';
            $notes[] = 'Live hotel provider unavailable or empty. Local fallback active.';
        } else {
            $notes[] = 'Live hotel provider active: Geoapify.';
        }

        foreach ($rows as $row) {
            $nightly = (int) ($row['price_per_night'] ?? 0);
            $total = $nightly * $nights * $rooms;
            $amenities = array_map('strtolower', $row['amenities'] ?? []);
            $interestBonus = count(array_intersect($amenities, $request->interests));
            $score = round(((float) ($row['rating'] ?? 0) * 1.25) + $interestBonus - min($total / max($request->budget, 1), 1.2), 2);
            $items[] = new Recommendation(
                title: (string) ($row['name'] ?? 'Hotel'),
                details: sprintf(
                    'Area: %s | Khach: %d nguoi lon, %d tre em | Phong: %d | Rating: %s | Gia: %s/dem/phong x %d phong x %d dem | Source: %s',
                    $row['area'] ?? $request->destination,
                    $request->adults,
                    $request->children,
                    $rooms,
                    $row['rating'] ?? '?',
                    number_format($nightly, 0, ',', '.'),
                    $rooms,
                    $nights,
                    $row['source'] ?? $source,
                ),
                price: $total,
                score: $score,
                reason: 'Xep hang theo rating, so thich va muc do phu hop ngan sach.',
            );
        }

        usort($items, fn (Recommendation $a, Recommendation $b): int => [$b->score, $a->price] <=> [$a->score, $b->price]);

        if ($items === []) {
            return new AgentResult($this->name, 'No stable hotel discovery data available.', [], $notes, $source, 'empty', ['room_plan' => compact('rooms')]);
        }

        return new AgentResult(
            agent: $this->name,
            summary: 'Found '.count($items).' hotel options.',
            recommendations: array_slice($items, 0, 4),
            notes: $notes,
            source: $source,
            status: 'ok',
            extra: ['room_plan' => compact('rooms', 'nights'), 'hotel_candidates' => $rows],
        );
    }
}
