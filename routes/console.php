<?php

use App\TravelPlanner\DTO\Recommendation;
use App\Support\Text;
use App\TravelPlanner\Services\RequestParser;
use App\TravelPlanner\Services\RootTravelPlanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

Artisan::command('about:travel-planner', function (): void {
    $this->info('Travel Planner A2A Laravel migration');
});

Artisan::command('travel-planner:snapshot {--kind=stability : stability or ten-case} {--output= : JSON output path} {--limit= : Limit cases for smoke runs}', function (RequestParser $parser, RootTravelPlanner $planner): int {
    $kind = (string) ($this->option('kind') ?: 'stability');
    $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
    $cases = match ($kind) {
        'ten-case' => [
            ['Ha Noi', 3, 7000000, '2026-05-10'],
            ['Ho Chi Minh', 3, 8000000, '2026-05-10'],
            ['Da Nang', 3, 7000000, '2026-05-10'],
            ['Da Lat', 4, 8000000, '2026-05-10'],
            ['Nha Trang', 3, 8000000, '2026-05-10'],
            ['Hue', 3, 7000000, '2026-05-10'],
            ['Hoi An', 3, 7000000, '2026-05-10'],
            ['Ha Long', 3, 7000000, '2026-05-10'],
            ['Phu Quoc', 3, 10000000, '2026-05-10'],
            ['Ninh Binh', 3, 7000000, '2026-05-10'],
        ],
        'stability' => [
            ['Da Lat', 4, 8000000, '2026-05-10'],
            ['Da Nang', 3, 7000000, '2026-05-10'],
            ['Nha Trang', 3, 8000000, '2026-05-10'],
            ['Ha Long', 3, 7000000, '2026-05-10'],
            ['Phu Quoc', 3, 10000000, '2026-05-10'],
            ['Hue', 3, 7000000, '2026-05-10'],
            ['Hoi An', 3, 7000000, '2026-05-10'],
        ],
        default => null,
    };

    if ($cases === null) {
        $this->error('Unknown kind. Use stability or ten-case.');
        return 1;
    }
    if ($limit !== null) {
        $cases = array_slice($cases, 0, $limit);
    }

    $rows = [];
    foreach ($cases as [$destination, $days, $budget, $departureDate]) {
        $request = $parser->parse("Tôi muốn đi {$destination} {$days} ngày với ngân sách {$budget}", 'Ha Noi');
        $request->lang = 'vi';
        $request->departureDate = $departureDate;
        $request->days = $days;
        $request->budget = $budget;

        $plan = $planner->run($request);
        $row = [
            'destination' => $destination,
            'days' => $days,
            'budget' => $budget,
            'departure_date' => $departureDate,
            'provider_status' => $plan->providerStatus,
            'transport_count' => count($plan->transportOptions),
            'hotel_count' => count($plan->hotels),
            'attraction_count' => count($plan->attractions),
            'transport_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($plan->transportOptions, 0, 5)),
            'hotel_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($plan->hotels, 0, 5)),
            'attraction_titles' => array_map(fn (Recommendation $item): string => $item->title, array_slice($plan->attractions, 0, 5)),
            'estimated_cost' => $plan->estimatedCost,
        ];
        $rows[] = $row;
        $this->line(sprintf('%s: transport=%d hotel=%d attractions=%d', $destination, $row['transport_count'], $row['hotel_count'], $row['attraction_count']));
    }

    $summary = [
        'total_cases' => count($rows),
        'transport_ok' => count(array_filter($rows, fn (array $row): bool => $row['transport_count'] > 0)),
        'hotel_ok' => count(array_filter($rows, fn (array $row): bool => $row['hotel_count'] > 0)),
        'attraction_ok' => count(array_filter($rows, fn (array $row): bool => $row['attraction_count'] > 0)),
        'full_case_ok' => count(array_filter($rows, fn (array $row): bool => $row['transport_count'] > 0 && $row['hotel_count'] > 0 && $row['attraction_count'] > 0)),
    ];

    $payload = $kind === 'ten-case'
        ? $rows
        : ['summary' => $summary, 'cases' => $rows];
    $defaultFile = $kind === 'ten-case' ? 'ten_case_snapshot.json' : 'stability_batch_latest.json';
    $output = (string) ($this->option('output') ?: storage_path('app/travel_data/'.$defaultFile));
    File::ensureDirectoryExists(dirname($output));
    File::put($output, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->info('Saved to '.$output);

    return 0;
});

Artisan::command('travel-planner:compare-snapshot {python : Python snapshot JSON path} {laravel : Laravel snapshot JSON path} {--output= : JSON comparison report path}', function (): int {
    $pythonPath = (string) $this->argument('python');
    $laravelPath = (string) $this->argument('laravel');
    if (! File::exists($pythonPath)) {
        $this->error('Python snapshot not found: '.$pythonPath);
        return 1;
    }
    if (! File::exists($laravelPath)) {
        $this->error('Laravel snapshot not found: '.$laravelPath);
        return 1;
    }

    $pythonPayload = json_decode(File::get($pythonPath), true);
    $laravelPayload = json_decode(File::get($laravelPath), true);
    if (! is_array($pythonPayload) || ! is_array($laravelPayload)) {
        $this->error('Both snapshot files must contain valid JSON objects or arrays.');
        return 1;
    }

    $pythonRows = snapshotRows($pythonPayload);
    $laravelRows = snapshotRows($laravelPayload);
    $laravelByDestination = [];
    foreach ($laravelRows as $row) {
        $laravelByDestination[Text::asciiFold((string) ($row['destination'] ?? ''))] = $row;
    }

    $cases = [];
    foreach ($pythonRows as $pythonRow) {
        $destination = (string) ($pythonRow['destination'] ?? '');
        $key = Text::asciiFold($destination);
        $laravelRow = $laravelByDestination[$key] ?? null;
        $case = [
            'destination' => $destination,
            'matched' => $laravelRow !== null,
            'issues' => [],
        ];

        if (! $laravelRow) {
            $case['issues'][] = 'missing_laravel_case';
            $cases[] = $case;
            continue;
        }

        foreach (['transport', 'hotel', 'attraction'] as $domain) {
            $countKey = $domain.'_count';
            $pythonCount = (int) ($pythonRow[$countKey] ?? 0);
            $laravelCount = (int) ($laravelRow[$countKey] ?? 0);
            $pythonStatus = snapshotProviderStatus($pythonRow, $domain);
            $laravelStatus = snapshotProviderStatus($laravelRow, $domain);
            $pythonTitles = snapshotTitles($pythonRow, $domain);
            $laravelTitles = snapshotTitles($laravelRow, $domain);
            $overlap = snapshotTitleOverlap($pythonTitles, $laravelTitles);
            $case[$domain] = [
                'python_count' => $pythonCount,
                'laravel_count' => $laravelCount,
                'count_equal' => $pythonCount === $laravelCount,
                'python_status' => $pythonStatus,
                'laravel_status' => $laravelStatus,
                'status_equal' => $pythonStatus === $laravelStatus,
                'python_titles' => $pythonTitles,
                'laravel_titles' => $laravelTitles,
                'title_overlap' => $overlap,
            ];
            if ($pythonCount !== $laravelCount) {
                $case['issues'][] = $domain.'_count_mismatch';
            }
            if ($pythonStatus !== '' && $laravelStatus !== '' && $pythonStatus !== $laravelStatus) {
                $case['issues'][] = $domain.'_status_mismatch';
            }
            if ($pythonTitles !== [] && $laravelTitles !== [] && $overlap === 0) {
                $case['issues'][] = $domain.'_title_no_overlap';
            }
        }

        $pythonCost = (int) ($pythonRow['estimated_cost'] ?? 0);
        $laravelCost = (int) ($laravelRow['estimated_cost'] ?? 0);
        $case['estimated_cost'] = [
            'python' => $pythonCost,
            'laravel' => $laravelCost,
            'delta' => $laravelCost - $pythonCost,
        ];
        $cases[] = $case;
    }

    $summary = [
        'total_python_cases' => count($pythonRows),
        'total_laravel_cases' => count($laravelRows),
        'matched_cases' => count(array_filter($cases, fn (array $case): bool => $case['matched'])),
        'clean_cases' => count(array_filter($cases, fn (array $case): bool => $case['matched'] && $case['issues'] === [])),
        'issue_count' => array_sum(array_map(fn (array $case): int => count($case['issues']), $cases)),
    ];
    $report = [
        'summary' => $summary,
        'cases' => $cases,
    ];

    $output = (string) ($this->option('output') ?: storage_path('app/travel_data/snapshot_compare_latest.json'));
    File::ensureDirectoryExists(dirname($output));
    File::put($output, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $this->info('Saved to '.$output);

    return 0;
});

if (! function_exists('snapshotRows')) {
    function snapshotRows(array $payload): array
    {
        $rows = array_is_list($payload) ? $payload : ($payload['cases'] ?? []);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}

if (! function_exists('snapshotProviderStatus')) {
    function snapshotProviderStatus(array $row, string $domain): string
    {
        $key = $domain === 'hotel' ? 'hotels' : ($domain === 'transport' ? 'transport' : 'attractions');

        return (string) data_get($row, "provider_status.{$key}.status", '');
    }
}

if (! function_exists('snapshotTitles')) {
    function snapshotTitles(array $row, string $domain): array
    {
        $keys = match ($domain) {
            'hotel' => ['hotel_titles', 'hotels'],
            'transport' => ['transport_titles', 'transport'],
            default => ['attraction_titles', 'attractions'],
        };
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if (is_array($value)) {
                return array_values(array_filter(array_map('strval', $value), fn (string $title): bool => trim($title) !== ''));
            }
        }

        return [];
    }
}

if (! function_exists('snapshotTitleOverlap')) {
    function snapshotTitleOverlap(array $left, array $right): int
    {
        $rightSet = array_flip(array_map(fn (string $title): string => Text::asciiFold($title), $right));
        $count = 0;
        foreach ($left as $title) {
            if (isset($rightSet[Text::asciiFold($title)])) {
                $count++;
            }
        }

        return $count;
    }
}
