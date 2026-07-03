<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\DailyPlan;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;

final class ItineraryOptimizerAgent
{
    public string $name = 'itinerary-optimizer-agent';

    /**
     * @param array<int, Recommendation> $transport
     * @param array<int, Recommendation> $hotels
     * @param array<int, Recommendation> $attractions
     */
    public function run(UserRequest $request, string $weatherSummary, array $transport, array $hotels, array $attractions): array
    {
        $selected = array_slice($attractions, 0, max($request->days, 1));
        $hotelName = $hotels[0]->title ?? 'selected hotel';
        $isVi = strtolower($request->lang) === 'vi';
        $daily = [];

        if ($selected === []) {
            for ($day = 1; $day <= $request->days; $day++) {
                $daily[] = new DailyPlan(
                    day: $day,
                    title: ($isVi ? 'Ngày ' : 'Day ').$day.' - '.$request->destination,
                    morning: $isVi ? 'Bắt đầu nhẹ quanh điểm đến, giữ lịch linh hoạt.' : 'Start with a relaxed exploration around the destination.',
                    afternoon: $isVi ? 'Chọn quán ăn, cà phê hoặc điểm trong nhà theo thời tiết.' : 'Choose food, cafes, or indoor stops based on weather.',
                    evening: ($isVi ? 'Ăn tối và nghỉ đêm tại ' : 'Dinner and overnight at ').$hotelName.'.',
                    estimatedCost: -1,
                );
            }
        } else {
            for ($day = 1; $day <= $request->days; $day++) {
                $attraction = $selected[($day - 1) % count($selected)];
                $daily[] = new DailyPlan(
                    day: $day,
                    title: ($isVi ? 'Ngày ' : 'Day ').$day.' - '.$attraction->title,
                    morning: ($isVi ? 'Ưu tiên ' : 'Prioritize ').$attraction->title.($isVi ? ' vào buổi sáng.' : ' in the morning.'),
                    afternoon: $day === $request->days
                        ? ($isVi ? 'Giữ buổi chiều nhẹ để mua sắm nhỏ và chuẩn bị về.' : 'Keep the afternoon light for small shopping and departure prep.')
                        : ($isVi ? 'Khám phá khu vực lân cận, ăn uống hoặc cà phê địa phương.' : 'Explore nearby areas, local food, or cafes.'),
                    evening: ($isVi ? 'Ăn tối và nghỉ đêm tại ' : 'Dinner and overnight at ').$hotelName.'.',
                    estimatedCost: $attraction->price > 0 ? $attraction->price : -1,
                );
            }
        }

        $breakdown = $this->buildBudgetBreakdown($request, $transport, $hotels, $daily);
        $issues = [];
        if ($breakdown['budget_gap'] > 0) {
            $issues[] = 'over_budget';
        }
        if ($transport === []) {
            $issues[] = 'missing_transport_price_signal';
        }
        if ($hotels === []) {
            $issues[] = 'missing_lodging_price_signal';
        }

        return [
            'daily_itinerary' => $daily,
            'total_cost' => $breakdown['total'],
            'budget_breakdown' => $breakdown,
            'optimization_score' => max(0, 10 - count($issues)),
            'issues' => $issues,
            'decisions' => ['Built day-by-day itinerary with one primary attraction per day.', 'Evaluated budget fit and missing provider signals.'],
            'revision_count' => 0,
        ];
    }

    private function buildBudgetBreakdown(UserRequest $request, array $transport, array $hotels, array $daily): array
    {
        $travelers = max($request->travelers, 1);
        $days = max($request->days, 1);
        $nights = max($days - 1, 1);
        $perPersonDayBudget = $request->budget / $travelers / $days;

        $transportCost = ($transport[0]->price ?? 0) > 0 ? $transport[0]->price * $travelers * 2 : 0;
        $lodging = ($hotels[0]->price ?? 0) > 0 ? $hotels[0]->price : $this->fallbackLodging($request) * $nights;
        $attractions = array_sum(array_map(fn (DailyPlan $day): int => max($day->estimatedCost, 0), $daily)) * $travelers;
        $meals = $this->mealAllowance($perPersonDayBudget) * $days * $travelers;
        $localTransport = $this->localTransportAllowance($perPersonDayBudget) * $days * $travelers;
        $experience = $this->experienceAllowance($request, $perPersonDayBudget) * $days * $travelers;
        $shopping = $this->shoppingAllowance($perPersonDayBudget) * $travelers;
        $subtotal = $transportCost + $lodging + $attractions + $meals + $localTransport + $experience + $shopping;
        $contingency = (int) round($subtotal * 0.08);
        $total = $subtotal + $contingency;

        return [
            'transport' => $transportCost,
            'lodging' => $lodging,
            'attractions' => $attractions,
            'meals' => $meals,
            'local_transport' => $localTransport,
            'experience' => $experience,
            'shopping' => $shopping,
            'daily_allowance' => $meals + $localTransport,
            'experience_adjustment' => $experience,
            'contingency' => $contingency,
            'total' => $total,
            'target_budget' => $request->budget,
            'budget_gap' => $total - $request->budget,
            'budget_fit' => $total <= $request->budget,
            'method' => 'round_trip_transport + lodging + attraction_tickets + meals + local_transport + experience + shopping + 8_percent_contingency',
            'notes' => ['Budget was recalculated from the optimized itinerary in Laravel.'],
        ];
    }

    private function fallbackLodging(UserRequest $request): int
    {
        $perPerson = $request->budget / max($request->travelers, 1);
        return match (true) {
            $perPerson <= 5000000 => 550000,
            $perPerson <= 12000000 => 850000,
            $perPerson <= 25000000 => 1400000,
            default => 2200000,
        };
    }

    private function mealAllowance(float $budget): int
    {
        return match (true) {
            $budget <= 700000 => 220000,
            $budget <= 1500000 => 350000,
            $budget <= 3000000 => 550000,
            default => 850000,
        };
    }

    private function localTransportAllowance(float $budget): int
    {
        return match (true) {
            $budget <= 700000 => 90000,
            $budget <= 1500000 => 140000,
            $budget <= 3000000 => 220000,
            default => 350000,
        };
    }

    private function experienceAllowance(UserRequest $request, float $budget): int
    {
        $base = $budget > 3000000 ? 450000 : ($budget > 1500000 ? 250000 : 120000);
        return array_intersect($request->interests, ['food', 'coffee', 'culture', 'history', 'photo', 'beach', 'nature']) ? $base + 80000 : $base;
    }

    private function shoppingAllowance(float $budget): int
    {
        return match (true) {
            $budget <= 700000 => 100000,
            $budget <= 1500000 => 250000,
            $budget <= 3000000 => 500000,
            default => 1000000,
        };
    }
}
