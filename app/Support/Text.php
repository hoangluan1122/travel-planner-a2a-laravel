<?php

namespace App\Support;

final class Text
{
    public static function asciiFold(?string $value): string
    {
        $text = trim((string) $value);
        $text = str_replace(['đ', 'Đ'], ['d', 'D'], $text);
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $converted === false ? $text : $converted;
        return strtolower(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    public static function money(int|float|null $value): string
    {
        return number_format((float) ($value ?? 0), 0, ',', '.').' VND';
    }
}
