<?php

namespace App\TravelPlanner\Services;

use App\Support\Text;
use Illuminate\Support\Facades\File;

final class TravelDataRepository
{
    public function hotels(string $destination): array
    {
        return $this->forDestination('hotels.json', $destination);
    }

    public function flights(string $destination): array
    {
        return $this->forDestination('flights.json', $destination);
    }

    public function attractions(string $destination): array
    {
        return $this->forDestination('attractions.json', $destination);
    }

    public function locations(): array
    {
        return $this->readJson('vn_locations.json');
    }

    private function forDestination(string $file, string $destination): array
    {
        $target = Text::asciiFold($destination);
        return array_values(array_filter($this->readJson($file), function (array $row) use ($target): bool {
            return Text::asciiFold((string) ($row['destination'] ?? '')) === $target;
        }));
    }

    private function readJson(string $file): array
    {
        $path = storage_path('app/travel_data/'.$file);
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);
        return is_array($decoded) ? $decoded : [];
    }
}
