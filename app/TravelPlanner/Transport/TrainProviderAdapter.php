<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use Illuminate\Support\Facades\Http;

final class TrainProviderAdapter extends EstimatedGroundAdapter
{
    public function search(UserRequest $request): array
    {
        $live = $this->liveSearch($request);
        if ($live !== []) {
            return $live;
        }

        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);
        if (empty($origin['train_station_code']) || empty($destination['train_station_code'])) {
            return [];
        }

        $distance = $this->distanceKm($request);
        $price = max(120000, (int) round($distance * 950));

        return [
            new TransportOption(
                mode: 'train',
                provider: 'DSVN estimated adapter',
                operator: 'Tau hoa',
                departure: (string) ($origin['nearest_train_hub'] ?? $origin['canonical_name']),
                arrival: (string) ($destination['nearest_train_hub'] ?? $destination['canonical_name']),
                price: $price,
                duration: $this->durationFromSpeed($distance, 55),
                reason: 'Ước tính tuyến tàu từ dữ liệu ga; sẽ thay bằng DSVN live provider khi adapter DSVN được port đầy đủ.',
                priceVerified: false,
                fareLabel: 'giá ước tính',
            ),
        ];
    }

    /**
     * @return array<int, TransportOption>
     */
    private function liveSearch(UserRequest $request): array
    {
        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);
        $originCode = $origin['train_station_code'] ?? null;
        $destinationCode = $destination['train_station_code'] ?? null;
        if (! $originCode || ! $destinationCode) {
            return [];
        }

        $date = $request->departureDate ?: now()->addDays(7)->toDateString();
        try {
            $rows = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])
                ->get('https://k.vnticketonline.vn/api/GTGV/LoadDmTau', [
                    'ngayDi' => $date,
                    'maGaDi' => $originCode,
                    'maGaDen' => $destinationCode,
                ])
                ->throw()
                ->json();
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($rows)) {
            return [];
        }

        $options = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $trainId = $row['Id'] ?? $row['TauId'] ?? null;
            $trainName = (string) ($row['MacTau'] ?? $row['TenTau'] ?? 'Train');
            $departureTime = (string) ($row['GioDi'] ?? '');
            $arrivalTime = (string) ($row['GioDen'] ?? '');
            [$price, $fareLabel, $verified] = $trainId ? $this->loadTrainPrice((int) $trainId, (string) $originCode) : [0, '', false];
            $reason = 'Kết quả tàu từ API tương thích DSVN.';
            if ($fareLabel !== '') {
                $reason .= ' '.$fareLabel.'.';
            }
            if (! $verified) {
                $reason .= ' Giá tàu chỉ mang tính tham khảo, chưa phải giá bán cuối cùng.';
            }

            $options[] = new TransportOption(
                mode: 'train',
                provider: 'DSVN API',
                operator: $trainName,
                departure: trim(($origin['canonical_name'] ?? $request->origin).' '.$departureTime),
                arrival: trim(($destination['canonical_name'] ?? $request->destination).' '.$arrivalTime),
                price: $price,
                duration: $this->minutesToDuration((int) ($row['Duration'] ?? 0)),
                score: 4.3,
                reason: $reason,
                priceVerified: $verified,
                fareLabel: $fareLabel,
            );
        }

        return $options;
    }

    /**
     * @return array{0: int, 1: string, 2: bool}
     */
    private function loadTrainPrice(int $trainId, string $originCode): array
    {
        try {
            $detail = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'travel-planner-a2a-laravel/1.0'])
                ->get('https://k.vnticketonline.vn/api/GTGV/LoadOneTau', [
                    'tauId' => $trainId,
                    'maGaDi' => $originCode,
                ])
                ->throw()
                ->json() ?: [];
        } catch (\Throwable) {
            return [0, '', false];
        }

        $softSeat = [];
        $sleeper = [];
        $fallback = [];
        foreach (($detail['BangGiaVes'] ?? []) as $seat) {
            if (! is_array($seat) || ! isset($seat['GiaVe'])) {
                continue;
            }
            $price = (int) round(((float) $seat['GiaVe']) * 1000);
            $label = trim((string) ($seat['TenLoaiCho'] ?? $seat['LoaiCho'] ?? ''));
            $labelSlug = mb_strtolower($label);
            if (str_contains($labelSlug, 'ghế phụ') || str_contains($labelSlug, 'ghe phu')) {
                continue;
            }
            if (str_contains($labelSlug, 'ngồi mềm') || str_contains($labelSlug, 'ngoi mem')) {
                $softSeat[] = [$price, $label];
            } elseif (str_contains($labelSlug, 'giường') || str_contains($labelSlug, 'giuong')) {
                $sleeper[] = [$price, $label];
            } else {
                $fallback[] = [$price, $label];
            }
        }

        foreach ([['Giá ghế mềm thấp nhất', $softSeat], ['Giá giường nằm thấp nhất', $sleeper], ['Giá tham khảo', $fallback]] as [$prefix, $prices]) {
            if ($prices === []) {
                continue;
            }
            usort($prices, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            return [$prices[0][0], trim($prefix.': '.$prices[0][1]), false];
        }

        return [0, '', false];
    }

    private function minutesToDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
