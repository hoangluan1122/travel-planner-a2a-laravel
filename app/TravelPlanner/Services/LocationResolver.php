<?php

namespace App\TravelPlanner\Services;

use App\Support\Text;

final class LocationResolver
{
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
        return [
            'input_value' => $raw,
            'canonical_name' => (string) ($record['name'] ?? $raw),
            'normalized_slug' => Text::asciiFold((string) ($record['name'] ?? $raw)),
            'iata' => $record['iata'] ?? null,
            'train_station_code' => $record['train_station_code'] ?? null,
            'bus_area_id' => $record['bus_area_id'] ?? null,
            'lat' => $record['lat'] ?? null,
            'lon' => $record['lon'] ?? null,
            'kind' => $record['kind'] ?? 'city',
            'matched_by' => $matchedBy,
            'nearest_airport_hub' => $record['nearest_airport_hub'] ?? null,
            'nearest_train_hub' => $record['nearest_train_hub'] ?? null,
            'nearest_bus_hub' => $record['nearest_bus_hub'] ?? null,
        ];
    }
}
