<?php

namespace App\Services\TallyLedger;

final class DealerTallyBalance
{
    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    public static function typeFromSigned(float $signed): string
    {
        return round($signed, 2) < 0 ? self::CREDIT : self::DEBIT;
    }

    public static function amountFromSigned(float $signed): float
    {
        return round(abs($signed), 2);
    }

    public static function signed(float $amount, string $type): float
    {
        $amount = round(abs($amount), 2);

        return strtolower($type) === self::CREDIT ? -$amount : $amount;
    }

    public static function matches(?float $leftAmount, ?string $leftType, ?float $rightAmount, ?string $rightType): bool
    {
        if ($leftAmount === null || $rightAmount === null || $leftType === null || $rightType === null) {
            return false;
        }

        if (round((float) $leftAmount, 2) === 0.0 && round((float) $rightAmount, 2) === 0.0) {
            return true;
        }

        return round((float) $leftAmount, 2) === round((float) $rightAmount, 2)
            && strtolower($leftType) === strtolower($rightType);
    }
}
