<?php

namespace Tests\Feature;

use App\TravelPlanner\Services\LiveTravelService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WikipediaAttractionParityTest extends TestCase
{
    public function test_wikipedia_geo_attractions_are_used_between_geoapify_and_overpass(): void
    {
        $this->setEnv('GEOAPIFY_API_KEY', '');

        Http::fake([
            'vi.wikipedia.org/w/api.php*list=geosearch*' => Http::response([
                'query' => [
                    'geosearch' => [
                        ['pageid' => 10, 'title' => 'Cau Rong'],
                        ['pageid' => 11, 'title' => 'Beach Hotel Da Nang'],
                        ['pageid' => 12, 'title' => 'Ngu Hanh Son'],
                        ['pageid' => 13, 'title' => 'Hue (thanh pho thuoc tinh)'],
                        ['pageid' => 14, 'title' => 'Bieu tinh Phat giao tai Hue 1993'],
                    ],
                ],
            ], 200),
            'vi.wikipedia.org/w/api.php*pageids=*' => Http::response([
                'query' => [
                    'pages' => [
                        '10' => [
                            'title' => 'Cau Rong',
                            'terms' => ['description' => ['bridge landmark near Han River']],
                            'thumbnail' => ['source' => 'https://img.example/cau-rong.jpg'],
                        ],
                        '11' => [
                            'title' => 'Beach Hotel Da Nang',
                            'terms' => ['description' => ['hotel']],
                            'thumbnail' => ['source' => 'https://img.example/hotel.jpg'],
                        ],
                        '12' => [
                            'title' => 'Ngu Hanh Son',
                            'terms' => ['description' => ['mountain cave and temple complex']],
                            'thumbnail' => ['source' => 'https://img.example/ngu-hanh-son.jpg'],
                        ],
                        '13' => [
                            'title' => 'Hue (thanh pho thuoc tinh)',
                            'terms' => ['description' => ['administrative division']],
                        ],
                        '14' => [
                            'title' => 'Bieu tinh Phat giao tai Hue 1993',
                            'terms' => ['description' => ['historical event']],
                        ],
                    ],
                ],
            ], 200),
            'en.wikipedia.org/w/api.php*' => Http::response(['query' => ['geosearch' => []]], 200),
            '*' => Http::response(['elements' => []], 200),
        ]);

        $rows = app(LiveTravelService::class)->fetchLiveAttractions('Da Nang', 5);

        $this->assertCount(2, $rows);
        $this->assertSame('Cau Rong', $rows[0]['name']);
        $this->assertSame('Wikipedia vi', $rows[0]['source']);
        $this->assertSame('https://img.example/cau-rong.jpg', $rows[0]['photo_url']);
        $this->assertSame('Ngu Hanh Son', $rows[1]['name']);
        $this->assertContains('nature', $rows[1]['interest_tags']);
        $this->assertContains('history', $rows[1]['interest_tags']);
        $this->assertNotContains('Beach Hotel Da Nang', array_column($rows, 'name'));
        $this->assertNotContains('Hue (thanh pho thuoc tinh)', array_column($rows, 'name'));
        $this->assertNotContains('Bieu tinh Phat giao tai Hue 1993', array_column($rows, 'name'));

        Http::assertSent(function ($request): bool {
            $query = $this->queryParams((string) $request->url());

            return str_contains((string) $request->url(), 'vi.wikipedia.org/w/api.php')
                && ($query['list'] ?? '') === 'geosearch'
                && ($query['gscoord'] ?? '') === '16.0544|108.2022'
                && ($query['gsradius'] ?? '') === '10000';
        });
        Http::assertSent(function ($request): bool {
            $query = $this->queryParams((string) $request->url());

            return str_contains((string) $request->url(), 'vi.wikipedia.org/w/api.php')
                && ($query['prop'] ?? '') === 'pageimages|pageterms'
                && ($query['pageids'] ?? '') === '10|11|12|13|14';
        });
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function queryParams(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

        return $query;
    }
}
