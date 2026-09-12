<?php

namespace App\Services\TallySync;

use App\Services\TallyLedger\DealerTallyBalance;

final class TallyClosingBalanceInterpreter
{
    /**
     * Interpret a live Tally closing-balance row from the connector.
     *
     * @param  array<string, mixed>  $row
     * @return array{amount: float, type: string, raw: string, numeric: float}
     */
    public static function interpret(array $row): array
    {
        $raw = self::normalizeRaw((string) ($row['closing_balance_raw'] ?? ''));
        $tallyIsDebit = self::triState($row['tally_is_debit'] ?? null);
        $deemedPositive = self::triState($row['deemed_positive'] ?? null);
        $reportedType = strtolower(trim((string) ($row['closing_balance_type'] ?? '')));
        $reportedAmount = (float) ($row['closing_balance'] ?? 0);

        if ($raw === '') {
            $type = in_array($reportedType, [DealerTallyBalance::DEBIT, DealerTallyBalance::CREDIT], true)
                ? $reportedType
                : DealerTallyBalance::DEBIT;

            return [
                'amount' => round(abs($reportedAmount), 2),
                'type' => $type,
                'raw' => '',
                'numeric' => round($reportedAmount, 2),
            ];
        }

        $lowered = strtolower($raw);
        $labelCredit = (bool) preg_match('/\bcr\b|\bcredit\b/', $lowered)
            || (bool) preg_match('/\(-\)\s*$/', $raw);
        $labelDebit = (bool) preg_match('/\bdr\b|\bdebit\b/', $lowered);
        $numericText = preg_replace('/[₹,\s]/u', '', $raw) ?? $raw;
        $numeric = 0.0;
        if (preg_match('/-?\d+(?:\.\d+)?/', $numericText, $matches) === 1) {
            $numeric = (float) $matches[0];
        }

        if ($tallyIsDebit === true) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($tallyIsDebit === false) {
            $type = DealerTallyBalance::CREDIT;
        } elseif ($labelDebit && ! $labelCredit) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($labelCredit && ! $labelDebit) {
            $type = DealerTallyBalance::CREDIT;
        } elseif ($numeric < 0) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($numeric > 0 && $deemedPositive === false) {
            $type = DealerTallyBalance::CREDIT;
        } else {
            $type = DealerTallyBalance::DEBIT;
        }

        return [
            'amount' => round(abs($numeric !== 0.0 ? $numeric : $reportedAmount), 2),
            'type' => $type,
            'raw' => $raw,
            'numeric' => round($numeric, 2),
        ];
    }

    private static function normalizeRaw(string $raw): string
    {
        return trim(str_replace(["\u{2212}", "\u{2013}", "\u{2014}"], '-', $raw));
    }

    private static function triState(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            if ((int) $value === 1) {
                return true;
            }
            if ((int) $value === 0) {
                return false;
            }

            return null;
        }

        $lowered = strtolower(trim((string) $value));
        if (in_array($lowered, ['yes', 'true', '1', 'y'], true)) {
            return true;
        }
        if (in_array($lowered, ['no', 'false', '0', 'n'], true)) {
            return false;
        }

        return null;
    }
}
