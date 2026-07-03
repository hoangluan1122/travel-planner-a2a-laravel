<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;

final class MixedTransportStrategy
{
    /**
     * @param array<int, TransportProviderAdapter> $providers
     */
    public function __construct(private readonly array $providers)
    {
    }

    /**
     * @return array<int, TransportOption>
     */
    public function getOptions(UserRequest $request): array
    {
        $preferred = $this->preferredTransport($request);
        $results = [];
        foreach ($this->providers as $provider) {
            $results = array_merge($results, $provider->search($request));
        }

        $scored = [];
        foreach ($results as $option) {
            [$score, $tag, $reason] = $this->scoreOption($option, $request);
            if ($preferred !== '' && $this->optionMatchesMode($option, $preferred)) {
                $score += $preferred === 'train' ? 3.0 : 1.2;
                $tag = $preferred === 'train' ? 'Tàu hỏa ưu tiên' : $tag;
                $reason = 'Ưu tiên phương tiện khách đã chọn. '.$reason;
            }

            $providerReason = trim($option->reason);
            $option->score = $score;
            $option->tag = $tag;
            $option->reason = trim($reason.' '.$providerReason);
            $scored[] = $option;
        }

        usort($scored, function (TransportOption $a, TransportOption $b) use ($preferred): int {
            if ($preferred !== '') {
                $aPreferred = $this->optionMatchesMode($a, $preferred) ? 0 : 1;
                $bPreferred = $this->optionMatchesMode($b, $preferred) ? 0 : 1;
                if ($aPreferred !== $bPreferred) {
                    return $aPreferred <=> $bPreferred;
                }
            }

            return [-$a->score, $a->price > 0 ? $a->price : PHP_INT_MAX, $this->durationToMinutes($a->duration)]
                <=> [-$b->score, $b->price > 0 ? $b->price : PHP_INT_MAX, $this->durationToMinutes($b->duration)];
        });

        if ($scored !== []) {
            $scored[0]->tag = 'Đề xuất chính';
            $primary = $preferred !== '' && $this->optionMatchesMode($scored[0], $preferred)
                ? "Phương án đúng phương tiện khách đã chọn: {$preferred}."
                : 'Phương án phù hợp nhất dựa trên chi phí, thời gian và mức phù hợp ngân sách.';
            $scored[0]->reason = trim($primary.' '.$scored[0]->reason);
        }

        return array_slice($scored, 0, 8);
    }

    /**
     * @return array{0: float, 1: string, 2: string}
     */
    private function scoreOption(TransportOption $option, UserRequest $request): array
    {
        $budget = max($request->budget, 1);
        $priceRatio = $option->price > 0 ? $option->price / $budget : 1;
        $duration = $this->durationToMinutes($option->duration);

        $affordability = match (true) {
            $priceRatio <= 0.12 => 5.0,
            $priceRatio <= 0.20 => 4.5,
            $priceRatio <= 0.30 => 3.8,
            $priceRatio <= 0.45 => 2.8,
            default => 1.2,
        };
        $speed = match (true) {
            $duration <= 90 => 5.0,
            $duration <= 180 => 4.3,
            $duration <= 360 => 3.5,
            $duration <= 720 => 2.5,
            default => 1.5,
        };
        $distanceFit = match ($option->mode) {
            'bus' => $duration <= 240 ? 4.8 : 2.0,
            'train' => $duration >= 180 && $duration <= 1200 ? 4.5 : 3.2,
            'flight' => $duration <= 240 ? 4.8 : 4.2,
            'mixed' => $duration <= 720 ? 4.0 : 3.0,
            default => 3.5,
        };

        $score = round($affordability * 0.45 + $speed * 0.25 + $distanceFit * 0.30, 2);
        if ($priceRatio <= 0.15) {
            return [$score, 'Tiết kiệm', 'Chi phí rất tốt so với ngân sách.'];
        }
        if ($duration <= 120 && $option->mode === 'flight') {
            return [$score, 'Nhanh nhất', 'Tiết kiệm thời gian di chuyển đáng kể.'];
        }
        if ($priceRatio <= 0.3 && $duration <= 480) {
            return [$score, 'Cân bằng', 'Cân bằng khá tốt giữa chi phí và thời gian.'];
        }

        return [$score, 'Phù hợp tuyến', 'Phù hợp với loại hành trình hiện tại.'];
    }

    private function preferredTransport(UserRequest $request): string
    {
        $preferred = strtolower(trim($request->preferredTransport));
        $aliases = [
            'flight' => ['flight', 'may bay', 'máy bay', 'plane', 'bay'],
            'train' => ['train', 'tau hoa', 'tàu hỏa', 'tau lua', 'rail'],
            'bus' => ['bus', 'xe khach', 'xe khách', 'limousine'],
            'car' => ['car', 'oto', 'o to', 'ô tô', 'xe rieng', 'xe riêng'],
            'mixed' => ['mixed', 'linh hoat', 'linh hoạt', 'noi chang', 'nối chặng'],
        ];
        foreach ($aliases as $mode => $values) {
            if ($preferred === $mode || in_array($preferred, $values, true)) {
                return $mode === 'mixed' ? '' : $mode;
            }
        }

        return $preferred;
    }

    private function optionMatchesMode(TransportOption $option, string $mode): bool
    {
        $text = strtolower("{$option->mode} {$option->provider} {$option->operator} {$option->reason} {$option->departure} {$option->arrival}");
        $aliases = [
            'flight' => ['flight', 'may bay', 'máy bay', 'serpapi google flights'],
            'train' => ['train', 'tau hoa', 'tàu hỏa', 'dsvn', 'rail'],
            'bus' => ['bus', 'xe khach', 'xe khách', 'limousine'],
            'car' => ['car', 'oto', 'o to', 'ô tô', 'road transfer', 'osrm'],
        ];

        return $option->mode === $mode || collect($aliases[$mode] ?? [$mode])->contains(fn (string $alias): bool => str_contains($text, $alias));
    }

    private function durationToMinutes(string $duration): int
    {
        if ($duration === '') {
            return 99999;
        }
        if (! preg_match('/(\d+)h(?:\s*(\d+)m)?/i', $duration, $matches)) {
            return 99999;
        }

        return ((int) $matches[1]) * 60 + (int) ($matches[2] ?? 0);
    }
}
