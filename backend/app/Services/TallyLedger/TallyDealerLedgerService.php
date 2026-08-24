<?php

namespace App\Services\TallyLedger;

use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;

final class TallyDealerLedgerService
{
    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     ledger: list<array<string, mixed>>,
     *     verification: array<string, mixed>
     * }
     */
    public function statement(Dealer $dealer): array
    {
        $account = $dealer->tallyLedger;
        $openingAmount = round((float) ($account?->opening_balance ?? 0), 2);
        $openingType = $account?->opening_balance_type ?: DealerTallyBalance::DEBIT;
        $openingSigned = DealerTallyBalance::signed($openingAmount, $openingType);
        $startDate = $account?->financial_start_date?->toDateString()
            ?: TallyLedgerConfig::FINANCIAL_START_DATE;

        $entries = [];
        $running = $openingSigned;

        $entries[] = [
            'date' => $startDate,
            'particulars' => 'Opening Balance',
            'voucher_type' => null,
            'voucher_no' => null,
            'debit' => $openingType === DealerTallyBalance::DEBIT ? $openingAmount : 0.0,
            'credit' => $openingType === DealerTallyBalance::CREDIT ? $openingAmount : 0.0,
            'balance_signed' => $running,
            'is_opening' => true,
        ];

        $rows = DealerTallyEntry::query()
            ->where('dealer_id', $dealer->id)
            ->where('entry_date', '>=', $startDate)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $totalDebit += $debit;
            $totalCredit += $credit;
            $running = round($running + $debit - $credit, 2);

            $entries[] = [
                'date' => $row->entry_date?->toDateString(),
                'particulars' => $row->particulars,
                'voucher_type' => $row->voucher_type,
                'voucher_no' => $row->voucher_no,
                'debit' => $debit,
                'credit' => $credit,
                'balance_signed' => $running,
                'is_opening' => false,
            ];
        }

        $tallyClosingAmount = $account?->tally_closing_balance !== null
            ? round((float) $account->tally_closing_balance, 2)
            : null;
        $tallyClosingType = $account?->tally_closing_balance_type;
        $matched = $tallyClosingAmount !== null && $tallyClosingType !== null
            && DealerTallyBalance::matches(
                DealerTallyBalance::amountFromSigned($running),
                DealerTallyBalance::typeFromSigned($running),
                $tallyClosingAmount,
                $tallyClosingType,
            );

        $tallySigned = $tallyClosingAmount !== null && $tallyClosingType !== null
            ? DealerTallyBalance::signed($tallyClosingAmount, $tallyClosingType)
            : null;

        return [
            'summary' => [
                'opening_balance' => $openingAmount,
                'opening_balance_type' => $openingType,
                'opening_balance_explicit' => (bool) ($account?->opening_balance_explicit ?? false),
                'opening_balance_label' => IndianCurrency::formatDrCr($openingSigned),
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
                'current_outstanding_signed' => $running,
                'current_outstanding' => DealerTallyBalance::amountFromSigned($running),
                'current_outstanding_type' => DealerTallyBalance::typeFromSigned($running),
                'current_outstanding_label' => IndianCurrency::formatDrCr($running),
                'has_tally_ledger' => $account !== null,
                'financial_start_date' => $startDate,
                'last_imported_at' => $account?->last_imported_at
                    ? Carbon::parse($account->last_imported_at)->timezone('Asia/Kolkata')->format('d M Y • h:i A')
                    : null,
            ],
            'ledger' => $entries,
            'verification' => [
                'tally_closing_balance' => $tallyClosingAmount,
                'tally_closing_balance_type' => $tallyClosingType,
                'tally_closing_label' => $tallySigned === null ? null : IndianCurrency::formatDrCr($tallySigned),
                'erp_closing_label' => IndianCurrency::formatDrCr($running),
                'balance_matched' => $tallyClosingAmount === null ? null : $matched,
                'difference' => $tallySigned === null ? null : round($running - $tallySigned, 2),
                'difference_label' => $tallySigned === null
                    ? null
                    : IndianCurrency::formatDrCr($running - $tallySigned),
            ],
        ];
    }
}
