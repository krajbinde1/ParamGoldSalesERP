<?php

namespace App\Services\TallySync;

use App\Services\TallyLedger\DealerTallyBalance;

final class TallyClosingBalanceInterpreter
{
    /**
     * Interpret a live Tally closing-balance row from the connector.
     *
     * Dr/Cr is taken from Tally indicators ($$IsDebit, $$IsNegative,
     * IsDeemedPositive, parent). A positive Sundry Debtor opening is Dr.
     *
     * @param  array<string, mixed>  $row
     * @return array{amount: float, type: string, raw: string, numeric: float}
     */
    public static function interpret(array $row): array
    {
        $raw = self::normalizeRaw((string) ($row['closing_balance_raw'] ?? ''));
        $tallyIsDebit = self::triState($row['tally_is_debit'] ?? null);
        $openingIsDebit = self::triState($row['opening_is_debit'] ?? null);
        $tallyIsNegative = self::triState($row['tally_is_negative'] ?? null);
        $deemedPositive = self::triState($row['deemed_positive'] ?? null);
        $parent = trim((string) ($row['ledger_parent'] ?? $row['parent'] ?? ''));
        $reportedType = strtolower(trim((string) ($row['closing_balance_type'] ?? '')));
        $reportedAmount = (float) ($row['closing_balance'] ?? 0);

        $numeric = $reportedAmount;
        $labelDebit = false;
        $labelCredit = false;
        if ($raw !== '') {
            $lowered = strtolower($raw);
            $labelCredit = (bool) preg_match('/\bcr\b|\bcredit\b/', $lowered)
                || (bool) preg_match('/\(-\)\s*$/', $raw);
            $labelDebit = (bool) preg_match('/\bdr\b|\bdebit\b/', $lowered);
            $numericText = preg_replace('/[₹,\s]/u', '', $raw) ?? $raw;
            if (preg_match('/-?\d+(?:\.\d+)?/', $numericText, $matches) === 1) {
                $numeric = (float) $matches[0];
            }
        } elseif (in_array($reportedType, [DealerTallyBalance::DEBIT, DealerTallyBalance::CREDIT], true)
            && $tallyIsDebit === null
            && $openingIsDebit === null
            && $deemedPositive === null) {
            return [
                'amount' => round(abs($reportedAmount), 2),
                'type' => $reportedType,
                'raw' => '',
                'numeric' => round($reportedAmount, 2),
            ];
        }

        $nature = self::ledgerNature($deemedPositive, $parent);
        $isNegative = $tallyIsNegative;
        if ($isNegative === null && $raw !== '') {
            $isNegative = $numeric < 0;
        }

        if ($labelDebit && ! $labelCredit) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($labelCredit && ! $labelDebit) {
            $type = DealerTallyBalance::CREDIT;
        } elseif ($tallyIsDebit === true || $openingIsDebit === true) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($nature === 'debit' && $isNegative === true) {
            $type = DealerTallyBalance::CREDIT;
        } elseif ($nature === 'credit' && $isNegative === true) {
            $type = DealerTallyBalance::DEBIT;
        } elseif ($nature === 'credit') {
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

    private static function ledgerNature(?bool $deemedPositive, string $parent): ?string
    {
        if ($deemedPositive === true) {
            return 'debit';
        }
        if ($deemedPositive === false) {
            return 'credit';
        }

        $text = strtolower(preg_replace('/\s+/', ' ', $parent) ?? $parent);
        if ($text === '') {
            return null;
        }

        foreach (['sundry creditor', 'sundry creditors', 'current liabilit', 'deposits', 'deposit (liability)', 'bank od', 'duties & tax', 'duties and tax'] as $name) {
            if (str_contains($text, $name)) {
                return 'credit';
            }
        }
        foreach (['sundry debtor', 'sundry debtors', 'current asset', 'cash-in-hand', 'bank accounts', 'direct expenses', 'indirect expenses'] as $name) {
            if (str_contains($text, $name)) {
                return 'debit';
            }
        }

        return null;
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
