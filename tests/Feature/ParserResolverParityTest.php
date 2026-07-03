<?php

namespace Tests\Feature;

use App\TravelPlanner\Services\LocationResolver;
use App\TravelPlanner\Services\RequestParser;
use Tests\TestCase;

final class ParserResolverParityTest extends TestCase
{
    public function test_parser_detects_train_preference_from_ui_text(): void
    {
        $parsed = app(RequestParser::class)->parse(
            'Toi muon di Sai Gon, 2 ngay, ngan sach 12 trieu, uu tien phuong tien tau hoa.',
            'Ha Noi',
        );

        $this->assertSame('train', $parsed->preferredTransport);
        $this->assertSame('Ho Chi Minh', $parsed->destination);
    }

    public function test_parser_keeps_destination_after_from_to_train_sentence(): void
    {
        $parsed = app(RequestParser::class)->parse(
            'Toi muon di bang tau hoa tu Ha Noi toi Sai Gon, 2 ngay, ngan sach 12 trieu.',
            'Ha Noi',
        );

        $this->assertSame('HAN', $parsed->origin);
        $this->assertSame('Ho Chi Minh', $parsed->destination);
        $this->assertSame('train', $parsed->preferredTransport);
    }

    public function test_parser_resolver_backbone_cases(): void
    {
        $cases = [
            ['Ha Noi', 'Toi muon di Nam Dinh 2 ngay voi ngan sach 4 trieu cho 2 nguoi.', 'Nam Dinh'],
            ['Ha Noi', 'Toi muon di Hai Phong 2 ngay voi ngan sach 5 trieu.', 'Hai Phong'],
            ['Ha Noi', 'Toi muon di Ha Long 3 ngay voi ngan sach 6 trieu cho 2 nguoi.', 'Ha Long'],
            ['Ha Noi', 'Toi muon di Ha Giang 3 ngay voi ngan sach 7 trieu cho 2 nguoi.', 'Ha Giang'],
            ['Ha Noi', 'Toi muon di Da Lat 3 ngay voi ngan sach 8 trieu cho 2 nguoi.', 'Da Lat'],
            ['Ha Noi', 'Toi muon di thanh pho Ho Chi Minh 3 ngay voi ngan sach 8 trieu cho 2 nguoi.', 'Ho Chi Minh'],
        ];

        foreach ($cases as [$origin, $text, $destination]) {
            $parsed = app(RequestParser::class)->parse($text, $origin);
            $resolved = app(LocationResolver::class)->resolve($parsed->destination);

            $this->assertSame($destination, $parsed->destination, $text);
            $this->assertSame($destination, $resolved['canonical_name'], $text);
            $this->assertNotEmpty($resolved['matched_by'], $text);
        }
    }

    public function test_location_backbone_has_expected_hubs(): void
    {
        $cases = [
            'Ha Noi', 'Hai Phong', 'Nam Dinh', 'Ninh Binh', 'Lao Cai', 'Sa Pa', 'Ha Giang', 'Cao Bang',
            'Quang Ninh', 'Hue', 'Da Nang', 'Hoi An', 'Da Lat', 'Nha Trang', 'Quy Nhon', 'Phan Thiet',
            'Ho Chi Minh', 'Vung Tau', 'Ben Tre', 'Can Tho', 'Phu Quoc', 'Con Dao', 'Mang Den', 'Tam Dao',
            'Bac Ninh', 'Hai Duong', 'Thai Binh', 'Thanh Hoa', 'Nghe An', 'Quang Tri', 'Quang Nam',
            'Binh Dinh', 'Khanh Hoa', 'Lam Dong', 'Ba Ria Vung Tau', 'Kien Giang',
        ];

        foreach ($cases as $value) {
            $resolved = app(LocationResolver::class)->resolve($value);

            $this->assertNotSame('passthrough', $resolved['matched_by'], $value);
            $this->assertNotEmpty($resolved['canonical_name'], $value);
            $this->assertTrue(
                ! empty($resolved['nearest_airport_hub']) || ! empty($resolved['nearest_train_hub']) || ! empty($resolved['nearest_bus_hub']),
                $value,
            );
        }
    }
}
