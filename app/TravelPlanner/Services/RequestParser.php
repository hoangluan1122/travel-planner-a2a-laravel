<?php

namespace App\TravelPlanner\Services;

use App\Support\Text;
use App\TravelPlanner\DTO\UserRequest;

final class RequestParser
{
    private const INTEREST_MAP = [
        'ca phe' => 'coffee',
        'coffee' => 'coffee',
        'chup hinh' => 'photo',
        'chup anh' => 'photo',
        'photo' => 'photo',
        'do an' => 'food',
        'am thuc' => 'food',
        'food' => 'food',
        'bien' => 'beach',
        'beach' => 'beach',
        'tam bien' => 'swimming',
        'boi' => 'swimming',
        'lich su' => 'history',
        'history' => 'history',
        'thien nhien' => 'nature',
        'nature' => 'nature',
        'nghi duong' => 'relax',
        'resort' => 'relax',
        'shopping' => 'shopping',
        'mua sam' => 'shopping',
        'culture' => 'culture',
        'van hoa' => 'culture',
    ];

    public function __construct(
        private readonly LocationResolver $locations,
        private readonly TravelDataRepository $data,
    ) {
    }

    public function parse(string $userText, ?string $origin = null): UserRequest
    {
        $text = trim($userText);
        $folded = Text::asciiFold($text);
        $guestProfile = $this->extractGuestProfile($folded);
        $destination = $this->locations->resolve($this->extractDestination($folded))['canonical_name'];

        return new UserRequest(
            destination: $destination,
            origin: $origin ? $this->locations->normalizeOrigin($origin) : $this->extractOrigin($folded),
            lang: $this->detectLang($text),
            departureDate: $this->extractDepartureDate($folded),
            preferredTransport: $this->extractPreferredTransport($folded),
            days: $this->extractDays($folded),
            budget: $this->extractBudget($folded),
            interests: $this->extractInterests($folded),
            travelers: $guestProfile['travelers'],
            adults: $guestProfile['adults'],
            children: $guestProfile['children'],
            childAges: $guestProfile['child_ages'],
        );
    }

    private function extractDestination(string $folded): string
    {
        if (preg_match('/(?:tu|from)\s+.+?\s+(?:toi|den|to)\s+([a-z0-9\s]+?)(?:\s+\d+\s*(?:ngay|days?)|\s+voi|\s+ngan\s*sach|\s+budget|,|$)/', $folded, $match)) {
            return trim($match[1]);
        }

        foreach ($this->data->locations() as $record) {
            foreach (array_merge([(string) ($record['name'] ?? '')], $record['aliases'] ?? []) as $name) {
                $slug = Text::asciiFold((string) $name);
                if ($slug !== '' && preg_match('/(?:di|den|du lich|tham quan|visit|to)\s+'.preg_quote($slug, '/').'(?![a-z0-9])/', $folded)) {
                    return (string) $record['name'];
                }
            }
        }

        if (preg_match('/(?:di|den|du lich|tham quan|visit|to)\s+([a-z0-9\s]+?)(?:\s+\d+\s*(?:ngay|days?)|\s+voi|\s+ngan\s*sach|\s+budget|,|$)/', $folded, $match)) {
            return trim($match[1]);
        }

        $known = [];
        foreach ($this->data->locations() as $record) {
            foreach (array_merge([(string) ($record['name'] ?? '')], $record['aliases'] ?? []) as $name) {
                $slug = Text::asciiFold((string) $name);
                if ($slug !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($slug, '/').'(?![a-z0-9])/', $folded)) {
                    $known[] = [strlen($slug), (string) $record['name']];
                    break;
                }
            }
        }
        if ($known !== []) {
            rsort($known);
            return $known[0][1];
        }

        return 'Da Nang';
    }

    private function extractOrigin(string $folded): string
    {
        if (preg_match('/(?:xuat\s*phat\s*tu|khoi\s*hanh\s*tu|tu|from)\s+([a-z0-9\s]+?)(?:\s*(?:,|di|toi|i want|muon)|$)/', $folded, $match)) {
            return $this->locations->normalizeOrigin(trim($match[1]));
        }
        return 'SGN';
    }

    private function extractDays(string $folded): int
    {
        return preg_match('/(?:trong\s*)?(\d+)\s*(?:ngay|days?)/', $folded, $match) ? (int) $match[1] : 3;
    }

    private function extractBudget(string $folded): int
    {
        if (preg_match('/(?:ngan\s*sach|budget|chi\s*phi|so\s*tien|tam|khoang|toi\s*da|max)[^\d]{0,24}(\d+[\.,]?\d*)\s*(?:tr|trieu|million|mil|m)\b/', $folded, $match)) {
            return (int) (floatval(str_replace(',', '.', $match[1])) * 1000000);
        }
        if (preg_match('/(\d+[\.,]?\d*)\s*(?:tr|trieu|million|mil)\b/', $folded, $match)) {
            return (int) (floatval(str_replace(',', '.', $match[1])) * 1000000);
        }
        if (preg_match('/(?:ngan\s*sach|budget|chi\s*phi|so\s*tien)[^\d]{0,24}(\d[\d\.,]*)/', $folded, $match)) {
            $raw = (int) preg_replace('/[^\d]/', '', $match[1]);
            return $raw < 1000 ? $raw * 1000000 : $raw;
        }
        return 8000000;
    }

    private function extractGuestProfile(string $folded): array
    {
        $travelers = preg_match('/(\d+)\s*(nguoi|person|people)/', $folded, $match) ? (int) $match[1] : 1;
        $adults = preg_match('/(\d+)\s*(?:nguoi\s*lon|adult|adults)/', $folded, $match) ? (int) $match[1] : 0;
        $children = preg_match('/(\d+)\s*(?:tre\s*em|em\s*be|be|child|children|kid|kids)/', $folded, $match) ? (int) $match[1] : 0;
        if ($adults <= 0 && $children <= 0) {
            $adults = $travelers;
        } elseif ($adults <= 0) {
            $adults = max($travelers - $children, 1);
        }

        $ages = [];
        if ($children > 0 && preg_match_all('/\b(\d{1,2})\b/', $folded, $matches)) {
            $ages = array_values(array_filter(array_map('intval', $matches[1]), fn (int $age) => $age >= 0 && $age <= 17));
            if (($ages[0] ?? null) === $children && count($ages) > $children) {
                array_shift($ages);
            }
        }

        return [
            'adults' => max($adults, 1),
            'children' => max($children, 0),
            'child_ages' => array_slice($ages, 0, $children),
            'travelers' => max($adults, 1) + max($children, 0),
        ];
    }

    private function extractInterests(string $folded): array
    {
        $interests = [];
        foreach (self::INTEREST_MAP as $needle => $tag) {
            if (str_contains($folded, $needle) && ! in_array($tag, $interests, true)) {
                $interests[] = $tag;
            }
        }
        return $interests;
    }

    private function extractPreferredTransport(string $folded): string
    {
        if (preg_match('/linh hoat|tu van|mixed|flexible/', $folded)) {
            return '';
        }
        $markers = '/muon di bang|uu tien|thich di|phuong tien|transport|prefer|di bang|bang|chon/';
        if (! preg_match($markers, $folded)) {
            return '';
        }
        return match (true) {
            preg_match('/may bay|flight|plane|bay/', $folded) === 1 => 'flight',
            preg_match('/tau hoa|tau lua|train|rail/', $folded) === 1 => 'train',
            preg_match('/xe khach|xe bus|bus|limousine/', $folded) === 1 => 'bus',
            preg_match('/oto|o to|xe rieng|car|private car/', $folded) === 1 => 'car',
            default => '',
        };
    }

    private function extractDepartureDate(string $folded): string
    {
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $folded, $match)) {
            return sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]);
        }
        if (preg_match('/(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?/', $folded, $match)) {
            return sprintf('%04d-%02d-%02d', $match[3] ?? (int) date('Y'), $match[2], $match[1]);
        }
        return '';
    }

    private function detectLang(string $text): string
    {
        return preg_match('/[à-ỹđ]|toi|muon|ngay|ngan sach|nguoi|du lich/i', $text) ? 'vi' : 'en';
    }
}
