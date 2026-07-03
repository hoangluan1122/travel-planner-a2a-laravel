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

        $checkin = $request->departureDate !== '' ? $request->departureDate : null;
        $checkout = $checkin ? date('Y-m-d', strtotime($checkin.' +'.$nights.' days')) : null;
        $rows = $this->live->fetchLiveHotels(
            destination: $request->destination,
            limit: 8,
            checkinDate: $checkin,
            checkoutDate: $checkout,
            adults: $request->adults,
            rooms: $rooms,
            children: $request->children,
            childAges: $request->childAges,
        );
        $source = $rows[0]['source'] ?? 'Live hotel provider';
        $notes = ["Hotel search used {$rooms} room(s) for {$request->adults} adult(s) and {$request->children} child(ren)."];
        if ($rows === []) {
            $rows = $this->data->hotels($request->destination);
            $source = 'Local fallback dataset';
            $notes[] = 'Live hotel provider unavailable or empty. Local fallback active.';
        } else {
            $notes[] = 'Live hotel provider active: '.$source.'.';
        }

        foreach ($rows as $row) {
            $nightly = (int) ($row['price_per_night'] ?? 0);
            $total = (int) ($row['total_price'] ?? 0);
            if ($total <= 0) {
                $total = $nightly * $nights * $rooms;
            }
            $amenities = array_map('strtolower', $row['amenities'] ?? []);
            $interestBonus = count(array_intersect($amenities, $request->interests));
            $score = round(((float) ($row['rating'] ?? 0) * 1.25) + $interestBonus - min($total / max($request->budget, 1), 1.2), 2);
            $badges = array_values(array_filter([
                ! empty($row['room_label']) ? 'Phòng: '.$row['room_label'] : '',
                ! empty($row['included_taxes']) ? 'Đã gồm thuế/phí' : '',
                ! empty($row['free_cancellation']) ? 'Hủy miễn phí' : '',
                ! empty($row['no_prepayment']) ? 'Không cần trả trước' : '',
                isset($row['distance_km']) ? 'Cách trung tâm khoảng '.$row['distance_km'].' km' : '',
                ! empty($row['price_source']) ? 'Nguồn giá: '.$row['price_source'] : '',
            ]));
            $items[] = new Recommendation(
                title: (string) ($row['name'] ?? 'Hotel'),
                details: sprintf(
                    'Khu vực: %s | Khách: %d người lớn, %d trẻ em | Phòng: %d | Rating: %s%s | Giá: %s/đêm/phòng x %d phòng x %d đêm = %s | Nguồn: %s',
                    $row['area'] ?? $request->destination,
                    $request->adults,
                    $request->children,
                    $rooms,
                    $row['rating'] ?? '?',
                    ! empty($row['review_count']) ? ' ('.$row['review_count'].' đánh giá)' : '',
                    number_format($nightly, 0, ',', '.'),
                    $rooms,
                    $nights,
                    number_format($total, 0, ',', '.').' VND',
                    $row['source'] ?? $source,
                ).($badges !== [] ? ' | '.implode(' | ', $badges) : ''),
                price: $total,
                score: $score,
                reason: 'Xếp hạng theo rating, nguồn giá live, sở thích và mức độ phù hợp ngân sách.',
                imageUrl: (string) ($row['photo_url'] ?? ''),
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
