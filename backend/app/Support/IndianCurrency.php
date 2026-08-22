<?php

namespace App\Support;

final class IndianCurrency
{
    public static function format(float|int|string|null $amount): string
    {
        $value = round((float) ($amount ?? 0), 2);
        $negative = $value < 0;
        $abs = abs($value);
        $paise = (int) round($abs * 100) % 100;
        $decimals = $paise === 0 ? 0 : 2;

        if ($decimals === 0) {
            $formatted = self::groupIndian((string) (int) round($abs));
        } else {
            $parts = explode('.', number_format($abs, 2, '.', ''));
            $formatted = self::groupIndian($parts[0]).'.'.($parts[1] ?? '00');
        }

        return ($negative ? '-' : '').'₹'.$formatted;
    }

    private static function groupIndian(string $integer): string
    {
        $integer = ltrim($integer, '0');

        if ($integer === '') {
            return '0';
        }

        $length = strlen($integer);

        if ($length <= 3) {
            return $integer;
        }

        $lastThree = substr($integer, -3);
        $rest = substr($integer, 0, -3);
        $groupedRest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;

        return $groupedRest.','.$lastThree;
    }
}
