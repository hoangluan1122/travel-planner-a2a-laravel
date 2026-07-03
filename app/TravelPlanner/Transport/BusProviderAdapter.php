<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;
use App\Support\Text;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class BusProviderAdapter extends EstimatedGroundAdapter
{
    private const AREA_OVERRIDES = [
        'Da Lat' => '457',
        'Lam Dong' => '457',
        'Vung Tau' => '76',
        'Ba Ria Vung Tau' => '76',
        'Can Tho' => '13',
        'Ninh Binh' => '42',
        'Tam Dao' => '752',
        'Ha Long' => '49',
        'Quang Ninh' => '49',
        'Ha Noi' => '24',
        'Da Nang' => '15',
        'Ho Chi Minh' => '29',
        'Hue' => '53',
        'Hoi An' => '14',
    ];

    public function search(UserRequest $request): array
    {
        $live = $this->liveSearch($request);
        if ($live !== []) {
            return $live;
        }

        $origin = $this->locations->resolve($request->origin);
        $destination = $this->locations->resolve($request->destination);
        $distance = $this->distanceKm($request);
        if ($distance > 900) {
            return [];
        }

        $price = max(90000, (int) round($distance * 1800));

        return [
            new TransportOption(
                mode: 'bus',
                provider: 'Bus estimated adapter',
                operator: 'Xe khách / limousine',
                departure: (string) $origin['canonical_name'],
                arrival: (string) $destination['canonical_name'],
                price: $price,
                duration: $this->durationFromSpeed($distance, 48),
                reason: 'Ước tính xe khách theo khoảng cách; sẽ thay bằng Vexere/public route provider khi adapter bus được port đầy đủ.',
                priceVerified: false,
                fareLabel: 'giá ước tính',
                originHub: $origin['nearest_bus_hub'] ?? null,
                destinationHub: $destination['nearest_bus_hub'] ?? null,
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
        $fromId = $this->resolveAreaId((string) ($origin['canonical_name'] ?? $request->origin));
        $toId = $this->resolveAreaId((string) ($destination['canonical_name'] ?? $request->destination));
        if (! $fromId || ! $toId) {
            return [];
        }

        try {
            $data = Http::timeout(10)
                ->withHeaders($this->guestHeaders())
                ->get('https://internal-vroute-cmc.vexere.com/v2/route', [
                    'filter[from]' => $fromId,
                    'filter[to]' => $toId,
                    'filter[date]' => $request->departureDate ?: now()->addDays(7)->toDateString(),
                    'page' => 1,
                    'pagesize' => 20,
                ])
                ->json() ?: [];
        } catch (\Throwable) {
            return [];
        }

        $items = $data['data'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $options = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $route = $item['route'] ?? [];
            $company = data_get($item, 'company.name') ?: data_get($route, 'company_name') ?: 'Bus operator';
            $schedule = ($route['schedules'] ?? [])[0] ?? [];
            $departureTime = $this->displayTime((string) (data_get($route, 'departure_time') ?: $this->scheduleTime($schedule)));
            $arrivalTime = $this->displayTime((string) data_get($schedule, 'arrival_time', ''));
            $price = (int) ((float) (data_get($schedule, 'fare.original') ?: data_get($route, 'min_price') ?: data_get($item, 'price') ?: 0));
            $duration = $this->minutesToDuration((int) (data_get($route, 'duration') ?: 0));
            $vehicleType = (string) (data_get($schedule, 'vehicle_type') ?: data_get($schedule, 'seat_template_name') ?: '');
            $availableSeats = data_get($schedule, 'available_seats');
            $reason = 'Kết quả xe khách từ luồng tra cứu Vexere.';
            if ($vehicleType !== '') {
                $reason .= ' Loại xe: '.$vehicleType.'.';
            }
            if ($availableSeats !== null) {
                $reason .= ' Số ghế còn lại: '.$availableSeats.'.';
            }

            $options[] = new TransportOption(
                mode: 'bus',
                provider: 'Vexere public route API',
                operator: (string) $company,
                departure: trim(($origin['canonical_name'] ?? $request->origin).' '.$departureTime),
                arrival: trim(($destination['canonical_name'] ?? $request->destination).' '.$arrivalTime),
                price: $price,
                duration: $duration,
                score: 4.0,
                reason: $reason,
                originHub: $origin['nearest_bus_hub'] ?? null,
                destinationHub: $destination['nearest_bus_hub'] ?? null,
            );
        }

        usort($options, fn (TransportOption $a, TransportOption $b): int => [$a->price ?: PHP_INT_MAX, $a->duration] <=> [$b->price ?: PHP_INT_MAX, $b->duration]);

        return array_slice($options, 0, 8);
    }

    private function resolveAreaId(string $location): ?string
    {
        $resolved = $this->locations->resolve($location);
        $canonical = (string) ($resolved['canonical_name'] ?? $location);
        if (isset(self::AREA_OVERRIDES[$canonical])) {
            return self::AREA_OVERRIDES[$canonical];
        }
        if (! empty($resolved['bus_area_id'])) {
            return (string) $resolved['bus_area_id'];
        }

        $cacheKey = 'bus_area_id_'.md5(Text::asciiFold($canonical));

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($canonical): ?string {
            try {
                $data = Http::timeout(8)
                    ->withHeaders($this->guestHeaders())
                    ->get('https://internal-vroute-cmc.vexere.com/v2/area', ['q' => Text::asciiFold($canonical)])
                    ->json() ?: [];
            } catch (\Throwable) {
                return null;
            }

            foreach (($data['data'] ?? $data) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? $item['Id'] ?? $item['area_id'] ?? '');
                $name = (string) ($item['name'] ?? $item['Name'] ?? $item['display_name'] ?? '');
                if ($id !== '' && Text::asciiFold($name) === Text::asciiFold($canonical)) {
                    return $id;
                }
            }

            return null;
        });
    }

    private function guestHeaders(): array
    {
        $headers = [
            'Accept-Language' => 'vi-VN',
            'origin-request-product' => 'FE_NEXTJS',
            'origin-request-id' => 'FE_NEXTJS_'.Str::uuid().'_'.Str::random(6),
            'User-Agent' => 'travel-planner-a2a-laravel/1.0',
        ];
        try {
            $token = Cache::remember('vexere_guest_token', now()->addMinutes(20), fn (): mixed => Http::timeout(6)->post('https://vexere.com/getToken')->json('access_token'));
            if (is_string($token) && $token !== '') {
                $headers['Authorization'] = 'bearer '.$token;
            }
        } catch (\Throwable) {
            //
        }

        return $headers;
    }

    private function scheduleTime(array $schedule): string
    {
        if (isset($schedule['hour'], $schedule['minute'])) {
            return sprintf('%02d:%02d', (int) $schedule['hour'], (int) $schedule['minute']);
        }

        return '';
    }

    private function displayTime(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return preg_match('/^\d{2}:\d{2}/', $value) ? substr($value, 0, 5) : $value;
        }
    }

    private function minutesToDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
