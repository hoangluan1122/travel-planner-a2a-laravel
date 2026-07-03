<?php

namespace App\TravelPlanner\Agents;

use App\TravelPlanner\DTO\AgentResult;
use App\TravelPlanner\DTO\Recommendation;
use App\TravelPlanner\DTO\UserRequest;
use App\TravelPlanner\Services\TravelDataRepository;
use App\TravelPlanner\Transport\TransportOption;
use App\TravelPlanner\Transport\TransportStrategyFactory;
use App\TravelPlanner\Transport\TransportValidator;

final class TransportAgent
{
    public string $name = 'transport-agent';

    public function __construct(
        private readonly TravelDataRepository $data,
        private readonly TransportStrategyFactory $strategies,
        private readonly TransportValidator $validator,
    )
    {
    }

    public function run(UserRequest $request): AgentResult
    {
        $strategy = $this->strategies->create($request);
        $options = $strategy->getOptions($request);
        $beforeValidation = count($options);
        [$options, $droppedNotes] = $this->validator->filterOptions($options, $request->origin, $request->destination);
        $notes = [
            'Strategy selected: '.class_basename($strategy),
            'Transport options returned before validation: '.$beforeValidation,
            'Transport options kept after validation: '.count($options),
            ...$droppedNotes,
        ];
        $source = 'Laravel native transport strategy';

        $items = array_map(fn (TransportOption $option): Recommendation => $this->toRecommendation($option), $options);

        if ($items === []) {
            $items = $this->fallbackFlights($request);
            $source = 'Local fallback dataset';
            $notes[] = 'Native transport strategy returned no valid option; local fallback active.';
        }

        if ($items === []) {
            return new AgentResult($this->name, 'No transport data available.', [], $notes, $source, 'empty');
        }

        if (! str_starts_with($items[0]->title, '[Đề xuất chính]')) {
            $items[0]->title = '[Đề xuất chính] '.$items[0]->title;
        }

        return new AgentResult($this->name, 'Found '.count($items).' transport options.', array_slice($items, 0, 8), $notes, $source, 'ok');
    }

    private function toRecommendation(TransportOption $option): Recommendation
    {
        $title = match ($option->mode) {
            'flight' => "{$option->operator} {$option->departure} -> {$option->arrival}",
            'train' => "{$option->operator} {$option->departure} -> {$option->arrival}",
            'bus' => "{$option->operator} {$option->departure} -> {$option->arrival}",
            'car' => "Ô tô {$option->departure} -> {$option->arrival}",
            default => "{$option->operator} {$option->departure} -> {$option->arrival}",
        };
        $details = implode(' | ', array_filter([
            ucfirst($option->mode),
            'Nguồn: '.$option->provider,
            $option->duration,
            $option->fareLabel,
        ]));

        return new Recommendation(
            title: $title,
            details: $details,
            price: $option->price,
            score: $option->score,
            reason: $option->reason,
        );
    }

    /**
     * @return array<int, Recommendation>
     */
    private function fallbackFlights(UserRequest $request): array
    {
        return array_map(function (array $row) use ($request): Recommendation {
            $price = (int) ($row['price'] ?? 0);
            return new Recommendation(
                title: sprintf('%s %s -> %s', $row['airline'] ?? 'Transport', $row['departure'] ?? '?', $row['arrival'] ?? '?'),
                details: 'Nguồn: '.($row['source'] ?? 'Local fallback dataset').' | Origin: '.$request->origin,
                price: $price,
                score: round(max(0, 100 - ($price / max($request->budget, 1)) * 100) / 20, 2),
                reason: 'Giá thấp hơn và phù hợp ngân sách được ưu tiên.',
            );
        }, $this->data->flights($request->destination));
    }

}
