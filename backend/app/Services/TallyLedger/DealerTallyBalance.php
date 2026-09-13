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

    public static function normalizeType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'cr', 'credit' => self::CREDIT,
            'dr', 'debit' => self::DEBIT,
            default => $normalized,
        };
    }

    public static function isCredit(?string $type): bool
    {
        return self::normalizeType($type) === self::CREDIT;
    }

    public static function signed(float $amount, string $type): float
    {
        $amount = round(abs($amount), 2);

        return self::isCredit($type) ? -$amount : $amount;
    }

    /**
     * ERP − Live in signed ledger units (Dr positive, Cr negative).
     * Same amount and same Dr/Cr yields 0 — it does not add the two balances.
     */
    public static function difference(float $leftSigned, float $rightSigned): float
    {
        return round($leftSigned - $rightSigned, 2);
    }

    /**
     * Compare a signed ERP outstanding to a Live Tally amount + Dr/Cr.
     *
     * @return array{matched: bool, difference: float, right_signed: float}
     */
    public static function compareSignedToBalance(float $leftSigned, float $rightAmount, string $rightType): array
    {
        $rightSigned = self::signed($rightAmount, $rightType);
        $difference = self::difference($leftSigned, $rightSigned);

        return [
            'matched' => $difference === 0.0,
            'difference' => $difference,
            'right_signed' => $rightSigned,
        ];
    }

    public static function matches(?float $leftAmount, ?string $leftType, ?float $rightAmount, ?string $rightType): bool
    {
        if ($leftAmount === null || $rightAmount === null || $leftType === null || $rightType === null) {
            return false;
        }

        if (round(abs((float) $leftAmount), 2) === 0.0 && round(abs((float) $rightAmount), 2) === 0.0) {
            return true;
        }

        return self::difference(
            self::signed((float) $leftAmount, $leftType),
            self::signed((float) $rightAmount, $rightType),
        ) === 0.0;
    }

    public static function creditTypeSql(string $column): string
    {
        return "LOWER(TRIM({$column})) IN ('credit', 'cr')";
    }
}
