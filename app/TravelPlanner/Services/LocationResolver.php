<?php

namespace App\TravelPlanner\Services;

use App\Support\Text;

final class LocationResolver
{
    private const AIRPORT_WEIGHT_BY_KIND = [
        'city' => 1.0,
        'province' => 1.05,
        'town' => 1.08,
        'landmark' => 1.15,
        'island' => 0.92,
    ];

    private const TRAIN_WEIGHT_BY_KIND = [
        'city' => 1.0,
        'province' => 1.0,
        'town' => 1.05,
        'landmark' => 1.1,
        'island' => 1.5,
    ];

    private const BUS_WEIGHT_BY_KIND = [
        'city' => 1.0,
        'province' => 0.98,
        'town' => 0.96,
        'landmark' => 0.95,
        'island' => 1.3,
    ];

    public function __construct(private readonly TravelDataRepository $data)
    {
    }

    public function resolve(?string $value): array
    {
        $raw = trim((string) $value);
        $slug = Text::asciiFold($raw);
        $locations = $this->data->locations();

        if ($raw === '') {
            $fallback = $locations[2] ?? ['name' => 'Da Nang'];
            return $this->shape($fallback, $raw, 'default');
        }

        foreach ($locations as $record) {
            if ($slug === Text::asciiFold((string) ($record['name'] ?? ''))) {
                return $this->shape($record, $raw, 'canonical');
            }
        }

        foreach ($locations as $record) {
            if (! empty($record['iata']) && $slug === strtolower((string) $record['iata'])) {
                return $this->shape($record, $raw, 'iata');
            }
        }

        foreach ($locations as $record) {
            $aliases = array_merge([(string) ($record['name'] ?? '')], $record['aliases'] ?? []);
            foreach ($aliases as $alias) {
                if ($slug === Text::asciiFold((string) $alias)) {
                    return $this->shape($record, $raw, 'alias');
                }
            }
        }

        foreach ($locations as $record) {
            $nameSlug = Text::asciiFold((string) ($record['name'] ?? ''));
            if ($slug !== '' && (str_contains($nameSlug, $slug) || str_contains($slug, $nameSlug))) {
                return $this->shape($record, $raw, 'fuzzy');
            }
        }

        return $this->shape(['name' => ucwords($raw)], $raw, 'passthrough');
    }

    public function normalizeOrigin(?string $origin): string
    {
        $cleaned = trim((string) $origin);
        if ($cleaned === '') {
            return 'SGN';
        }
        if (preg_match('/\(([A-Z]{3})\)/i', $cleaned, $match)) {
            return strtoupper($match[1]);
        }

        $resolved = $this->resolve($cleaned);
        if (! empty($resolved['iata'])) {
            return $resolved['iata'];
        }
        if (preg_match('/^[A-Z]{3}$/i', $cleaned)) {
            return strtoupper($cleaned);
        }

        return $resolved['canonical_name'];
    }

    private function shape(array $record, string $raw, string $matchedBy): array
    {
        $lat = $record['lat'] ?? null;
        $lon = $record['lon'] ?? null;

        return [
            'input_value' => $raw,
            'canonical_name' => (string) ($record['name'] ?? $raw),
            'normalized_slug' => Text::asciiFold((string) ($record['name'] ?? $raw)),
            'iata' => $record['iata'] ?? null,
            'train_station_code' => $record['train_station_code'] ?? null,
            'bus_area_id' => $record['bus_area_id'] ?? null,
            'lat' => $lat,
            'lon' => $lon,
            'kind' => $record['kind'] ?? 'city',
            'matched_by' => $matchedBy,
            'nearest_airport_hub' => $record['nearest_airport_hub']
                ?? $this->nearestHub($lat, $lon, 'iata', self::AIRPORT_WEIGHT_BY_KIND),
            'nearest_train_hub' => $record['nearest_train_hub']
                ?? $this->nearestHub($lat, $lon, 'train_station_code', self::TRAIN_WEIGHT_BY_KIND),
            'nearest_bus_hub' => $record['nearest_bus_hub']
                ?? $this->nearestHub($lat, $lon, 'bus_area_id', self::BUS_WEIGHT_BY_KIND),
        ];
    }

    /**
     * Mirrors the Python resolver: pick the closest provider-capable location,
     * with a small kind-based bias so islands/provinces/towns resolve sensibly.
     */
    private function nearestHub(mixed $lat, mixed $lon, string $field, array $weights): ?string
    {
        if (! is_numeric($lat) || ! is_numeric($lon)) {
            return null;
        }

        $bestScore = null;
        $bestName = null;
        foreach ($this->data->locations() as $candidate) {
            if (
                empty($candidate[$field])
                || ! is_numeric($candidate['lat'] ?? null)
                || ! is_numeric($candidate['lon'] ?? null)
            ) {
                continue;
            }

            $distance = $this->distanceKm((float) $lat, (float) $lon, (float) $candidate['lat'], (float) $candidate['lon']);
            $kind = (string) ($candidate['kind'] ?? 'city');
            $score = $distance * ($weights[$kind] ?? 1.0);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestName = (string) ($candidate['name'] ?? '');
            }
        }

        return $bestName !== '' ? $bestName : null;
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radiusKm = 6371.0;
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);
        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLon / 2) ** 2;

        return 2 * $radiusKm * asin(sqrt($a));
    }
}
