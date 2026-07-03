<?php

namespace App\TravelPlanner\Services;

use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;

final class TravelAdvisor
{
    private const BOOKING_GRADE_SOURCES = ['SerpAPI Google Hotels', 'RapidAPI booking-com15'];

    public function buildProfile(UserRequest $request): array
    {
        $travelers = max($request->travelers, 1);
        $days = max($request->days, 1);
        $budget = max($request->budget, 0);
        $perPersonDayBudget = $budget > 0 ? (int) ($budget / $travelers / $days) : 0;
        $budgetTier = match (true) {
            $perPersonDayBudget <= 700000 => 'tight',
            $perPersonDayBudget <= 1500000 => 'balanced',
            $perPersonDayBudget <= 3000000 => 'comfortable',
            default => 'premium',
        };

        $interests = array_map(fn (string $value): string => strtolower($value), $request->interests);
        $priorities = [];
        if (array_intersect(['food', 'coffee', 'ẩm thực', 'cafe'], $interests)) {
            $priorities[] = 'local_food';
        }
        if (array_intersect(['photo', 'nature', 'beach', 'chụp ảnh', 'thiên nhiên', 'biển'], $interests)) {
            $priorities[] = 'scenic';
        }
        if (array_intersect(['history', 'culture', 'lịch sử', 'văn hóa'], $interests)) {
            $priorities[] = 'culture';
        }
        if (array_intersect(['relax', 'resort', 'nghỉ dưỡng'], $interests)) {
            $priorities[] = 'comfort';
        }
        if ($priorities === []) {
            $priorities[] = 'balanced';
        }

        $missing = [];
        if ($request->departureDate === '') {
            $missing[] = 'departure_date';
        }
        if ($request->interests === []) {
            $missing[] = 'interests';
        }
        if ($request->origin === '') {
            $missing[] = 'origin';
        }

        return [
            'travelers' => $travelers,
            'days' => $days,
            'budget' => $budget,
            'per_person_day_budget' => $perPersonDayBudget,
            'budget_tier' => $budgetTier,
            'preferred_transport' => strtolower(trim($request->preferredTransport)),
            'priorities' => $priorities,
            'missing_signals' => $missing,
        ];
    }

    /**
     * @param array<int, Recommendation> $options
     * @return array<int, Recommendation>
     */
    public function adviseTransport(UserRequest $request, array $options): array
    {
        $profile = $this->buildProfile($request);
        $cap = $profile['budget'] * 0.34;
        $advised = [];
        foreach ($options as $option) {
            $roundTripTotal = (int) $option->price * $profile['travelers'] * 2;
            $delta = $this->budgetFitScore($roundTripTotal, $cap);
            $notes = [];
            if ($option->price > 0) {
                $notes[] = 'chi phí khứ hồi cho nhóm khoảng '.number_format($roundTripTotal).' VND';
            } else {
                $notes[] = 'chưa xác nhận được giá';
                $delta -= 0.8;
            }

            $lower = mb_strtolower($option->title.' '.$option->details.' '.$option->reason);
            if ((str_contains($lower, 'bus') || str_contains($lower, 'xe')) && in_array($profile['budget_tier'], ['tight', 'balanced'], true)) {
                $delta += 0.5;
                $notes[] = 'giá trị tốt so với ngân sách';
            }
            if ((str_contains($lower, 'flight') || str_contains($lower, 'máy bay')) && $profile['days'] <= 3) {
                $delta += 0.6;
                $notes[] = 'tiết kiệm thời gian cho chuyến ngắn ngày';
            }
            if ($profile['preferred_transport'] && $this->transportMatches($profile['preferred_transport'], $lower)) {
                $delta += $profile['preferred_transport'] === 'train' ? 3.0 : 1.4;
                $notes[] = 'đúng phương tiện khách ưu tiên: '.$profile['preferred_transport'];
            }

            $advised[] = $this->cloneWithAdvice($option, $option->score + $delta, 'Tư vấn di chuyển', $notes);
        }

        return $this->sort($advised);
    }

    public function adviseHotels(UserRequest $request, array $hotels): array
    {
        $profile = $this->buildProfile($request);
        $cap = $profile['budget'] * 0.42;
        $advised = [];
        foreach ($hotels as $hotel) {
            $delta = $this->budgetFitScore((int) $hotel->price, $cap);
            $notes = [];
            if ($hotel->price > 0) {
                $notes[] = 'chi phí lưu trú chiếm khoảng '.$this->percent($hotel->price, $profile['budget']).' ngân sách';
            } else {
                $notes[] = 'chưa có giá đặt phòng đủ tin cậy';
                $delta -= 0.9;
            }

            $text = $hotel->details.' '.$hotel->reason;
            if (collect(self::BOOKING_GRADE_SOURCES)->contains(fn (string $source): bool => str_contains($text, $source))) {
                $delta += 0.7;
                $notes[] = 'nguồn giá đặt phòng live';
            } elseif (str_contains($text, 'Booking price: unavailable')) {
                $delta -= 0.5;
                $notes[] = 'chỉ là dữ liệu khám phá, nên kiểm tra lại giá';
            }
            if (in_array('comfort', $profile['priorities'], true) && preg_match('/resort|hotel/i', $text)) {
                $delta += 0.3;
                $notes[] = 'hợp chuyến đi thiên về nghỉ dưỡng';
            }

            $advised[] = $this->cloneWithAdvice($hotel, $hotel->score + $delta, 'Tư vấn lưu trú', $notes);
        }

        return $this->sort($advised);
    }

    public function adviseAttractions(UserRequest $request, array $attractions, string $weatherSummary): array
    {
        $profile = $this->buildProfile($request);
        $interests = array_map(fn (string $value): string => mb_strtolower($value), $request->interests);
        $rainy = str_contains(mb_strtolower($weatherSummary), 'rain') || str_contains(mb_strtolower($weatherSummary), 'mưa');
        $advised = [];
        foreach ($attractions as $attraction) {
            $text = mb_strtolower($attraction->title.' '.$attraction->details.' '.$attraction->reason);
            $overlap = count(array_filter($interests, fn (string $interest): bool => $interest !== '' && str_contains($text, $interest)));
            $delta = min($overlap * 0.8, 2.0);
            $notes = [];
            if ($overlap > 0) {
                $notes[] = 'khớp sở thích đã nhập';
            }
            if ($rainy && str_contains($text, 'outdoor')) {
                $delta -= 0.8;
                $notes[] = 'điểm ngoài trời nhạy với thời tiết';
            }
            if ($attraction->price <= 0) {
                $delta += 0.2;
                $notes[] = 'chi phí vé thấp';
            }
            if (in_array('scenic', $profile['priorities'], true) && preg_match('/photo|nature|beach|chụp ảnh|thiên nhiên|biển/i', $text)) {
                $delta += 0.4;
                $notes[] = 'hợp nhu cầu cảnh đẹp/chụp ảnh';
            }

            $advised[] = $this->cloneWithAdvice($attraction, $attraction->score + $delta, 'Tư vấn điểm tham quan', $notes);
        }

        return $this->sort($advised);
    }

    public function buildAdvisorSummary(UserRequest $request, array $transport, array $hotels, array $attractions, int $estimatedCost): array
    {
        $profile = $this->buildProfile($request);
        $gap = $estimatedCost - $profile['budget'];
        $lines = [
            'Góc tư vấn: hệ thống đánh giá lựa chọn theo mức phù hợp với yêu cầu, không chỉ liệt kê dữ liệu live.',
            sprintf('Hồ sơ khách: %d người, %d ngày, ngân sách mỗi người mỗi ngày khoảng %s VND, nhóm %s.', $profile['travelers'], $profile['days'], number_format($profile['per_person_day_budget']), $profile['budget_tier']),
        ];
        if ($transport !== []) {
            $lines[] = 'Phương án nên ưu tiên: '.$transport[0]->title.'.';
        }
        if ($hotels !== []) {
            $lines[] = 'Lưu trú nên ưu tiên: '.$hotels[0]->title.'.';
        }
        if ($attractions !== []) {
            $lines[] = 'Trải nghiệm nên xếp trước: '.implode(', ', array_map(fn (Recommendation $item): string => $item->title, array_slice($attractions, 0, 3))).'.';
        }
        if ($profile['budget'] > 0) {
            $lines[] = $gap <= 0 ? 'Đánh giá ngân sách: phù hợp.' : 'Đánh giá ngân sách: vượt khoảng '.number_format($gap).' VND, nên đổi lựa chọn rẻ hơn hoặc tăng ngân sách.';
        }

        return [
            'summary' => implode("\n", $lines),
            'status' => [
                'profile' => $profile,
                'selection_method' => 'budget fit + source confidence + interest fit + trip duration trade-offs',
                'missing_signals' => $profile['missing_signals'],
                'budget_gap' => $gap,
                'primary_picks' => [
                    'transport' => $transport[0]->title ?? '',
                    'hotel' => $hotels[0]->title ?? '',
                    'attractions' => array_map(fn (Recommendation $item): string => $item->title, array_slice($attractions, 0, 3)),
                ],
                'agent_reviews' => $this->agentReviews($profile, $transport, $hotels, $attractions, $gap),
            ],
        ];
    }

    private function agentReviews(array $profile, array $transport, array $hotels, array $attractions, int $gap): array
    {
        return [
            $this->agentReview('TransportAgent', 'Chọn cách đi phù hợp với thời gian, ngân sách và phương tiện khách ưu tiên.', $transport[0] ?? null, 'Khách không khóa phương tiện, agent ưu tiên cân bằng chi phí và độ khả thi.'),
            $this->agentReview('HotelAgent', 'Lọc nơi lưu trú theo giá tổng kỳ nghỉ, độ tin cậy nguồn giá và mức thoải mái.', $hotels[0] ?? null, 'Giá booking-grade được ưu tiên hơn kết quả chỉ mang tính discovery.'),
            $this->agentReview('AttractionAgent', 'Xếp trải nghiệm theo số ngày, sở thích và điều kiện thời tiết.', $attractions[0] ?? null, in_array('interests', $profile['missing_signals'], true) ? 'Khách chưa nhập sở thích, agent chọn điểm khả thi và chi phí thấp.' : 'Các điểm có tag trùng sở thích được ưu tiên.'),
            [
                'agent' => 'RootAdvisor',
                'role' => 'Tổng hợp các agent con thành một gói đề xuất có thể hành động.',
                'pick' => $gap <= 0 ? 'Ngân sách phù hợp' : 'Cần cân chỉnh ngân sách',
                'confidence' => $gap <= 0 ? 'high' : 'medium',
                'reason' => $gap <= 0 ? 'Tổng chi phí ước tính nằm trong ngân sách khách đã nhập.' : 'Tổng chi phí ước tính vượt ngân sách khoảng '.number_format($gap).' VND.',
            ],
        ];
    }

    private function agentReview(string $agent, string $role, ?Recommendation $pick, string $reason): array
    {
        return [
            'agent' => $agent,
            'role' => $role,
            'pick' => $pick?->title ?? 'Chưa có phương án đủ tin cậy.',
            'confidence' => $pick && $pick->price > 0 ? 'high' : 'medium',
            'reason' => $reason,
        ];
    }

    private function budgetFitScore(int $cost, float $cap): float
    {
        if ($cost <= 0 || $cap <= 0) {
            return 0.0;
        }
        $ratio = $cost / $cap;
        return match (true) {
            $ratio <= 0.75 => 1.0,
            $ratio <= 1.0 => 0.6,
            $ratio <= 1.25 => -0.2,
            $ratio <= 1.6 => -0.8,
            default => -1.4,
        };
    }

    private function transportMatches(string $mode, string $text): bool
    {
        $aliases = [
            'flight' => ['flight', 'may bay', 'máy bay'],
            'train' => ['train', 'tau hoa', 'tàu hỏa'],
            'bus' => ['bus', 'xe khach', 'xe khách', 'limousine'],
            'car' => ['car', 'oto', 'o to', 'ô tô', 'road transfer'],
        ];

        return collect($aliases[$mode] ?? [$mode])->contains(fn (string $alias): bool => str_contains($text, $alias));
    }

    private function percent(int $value, int $total): string
    {
        return $total <= 0 ? 'không rõ' : round($value / $total * 100).'%';
    }

    private function cloneWithAdvice(Recommendation $option, float $score, string $label, array $notes): Recommendation
    {
        $advice = $notes === [] ? 'phù hợp dựa trên dữ liệu hiện có' : implode('; ', $notes);
        return new Recommendation(
            title: $option->title,
            details: str_contains($option->details, $advice) ? $option->details : trim($option->details.' | Tư vấn: '.$advice),
            price: $option->price,
            score: round($score, 2),
            reason: trim($label.': '.$advice.'. '.$option->reason),
            imageUrl: $option->imageUrl,
        );
    }

    private function sort(array $options): array
    {
        usort($options, fn (Recommendation $a, Recommendation $b): int => [-$a->score, $a->price > 0 ? $a->price : PHP_INT_MAX, $a->title] <=> [-$b->score, $b->price > 0 ? $b->price : PHP_INT_MAX, $b->title]);

        return $options;
    }
}
