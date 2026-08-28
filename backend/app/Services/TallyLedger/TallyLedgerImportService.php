<?php

namespace App\Services\TallyLedger;

use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyImport;
use App\Models\DealerTallyLedger;
use App\Models\TallyDealerMapping;
use App\Models\User;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Dealers\DealerSalesLedgerReconciler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class TallyLedgerImportService
{
    public function __construct(
        private readonly TallyLedgerExcelParser $parser = new TallyLedgerExcelParser,
        private readonly TallyDealerLedgerService $ledger = new TallyDealerLedgerService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(string $path, ?Dealer $dealer = null): array
    {
        return $this->previewParsed($this->parser->parse($path), $dealer);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewParsed(TallyLedgerParseResult $parsed, ?Dealer $dealer = null): array
    {
        $matched = $parsed->tallyClosingMatches();
        $importErrors = $parsed->importErrors();

        $tallyName = $parsed->tallyLedgerName;
        $namesDiffer = $dealer !== null
            && $tallyName !== ''
            && TallyDealerMapping::normalizeName($tallyName) !== TallyDealerMapping::normalizeName((string) $dealer->firm_name);

        Log::debug('tally_ledger_import_preview', [
            'tally_ledger_name' => $tallyName,
            'selected_erp_dealer_id' => $dealer?->id,
            'names_differ' => $namesDiffer,
            'transaction_count' => count($parsed->transactions),
            'opening_balance' => $parsed->openingBalance,
            'opening_balance_type' => $parsed->openingBalanceType,
            'tally_closing_balance' => $parsed->tallyClosingBalance,
            'tally_closing_balance_type' => $parsed->tallyClosingBalanceType,
            'can_import' => $importErrors === [],
            'parse_error_count' => count($importErrors),
        ]);

        $erpClosingSigned = $parsed->calculatedClosingSigned();
        $tallyClosingSigned = $parsed->tallyClosingBalance !== null && $parsed->tallyClosingBalanceType !== null
            ? DealerTallyBalance::signed($parsed->tallyClosingBalance, $parsed->tallyClosingBalanceType)
            : null;

        return [
            'tally_ledger_name' => $tallyName !== '' ? $tallyName : '—',
            'names_differ' => $namesDiffer,
            'opening_balance' => $parsed->openingBalance,
            'opening_balance_type' => $parsed->openingBalanceType,
            'opening_balance_explicit' => $parsed->openingBalanceExplicit,
            'transaction_count' => count($parsed->transactions),
            'total_debit' => $parsed->inclusiveTotalDebit(),
            'total_credit' => $parsed->inclusiveTotalCredit(),
            'transaction_debit' => $parsed->totalDebit,
            'transaction_credit' => $parsed->totalCredit,
            'tally_closing_balance' => $parsed->tallyClosingBalance,
            'tally_closing_balance_type' => $parsed->tallyClosingBalanceType,
            'erp_closing_balance' => DealerTallyBalance::amountFromSigned($erpClosingSigned),
            'erp_closing_balance_type' => DealerTallyBalance::typeFromSigned($erpClosingSigned),
            'erp_closing_signed' => $erpClosingSigned,
            'balance_matched' => $matched,
            'difference' => $tallyClosingSigned === null
                ? null
                : round($erpClosingSigned - $tallyClosingSigned, 2),
            'failed_count' => count($parsed->failed),
            'failed_rows' => $parsed->failed,
            'parse_errors' => $importErrors,
            'can_import' => $importErrors === [],
            'skipped_before_start_date' => $parsed->skippedBeforeStartDate,
            'transactions' => $parsed->transactions,
            'parsed' => $parsed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(string $path, int $dealerId, User $actor, string $originalFilename): array
    {
        $dealer = Dealer::query()->find($dealerId);
        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Open an existing ERP dealer before importing a Tally ledger.',
            ]);
        }

        $preview = $this->preview($path, $dealer);

        return $this->importPreview($preview, $dealer, $actor, $originalFilename);
    }

    /**
     * Remove Tally-imported ledger data for one dealer only.
     * Does not delete the dealer or any Orders, Collections, Visits, or ERP opening-balance fields.
     */
    public function resetForDealer(Dealer $dealer): void
    {
        DB::transaction(function () use ($dealer): void {
            $reconciledSales = DealerTallyEntry::query()
                ->where('dealer_id', $dealer->id)
                ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
                ->where(function ($query): void {
                    $query->whereNotNull('tally_reconciled_at')
                        ->orWhereNotNull('tally_voucher_no');
                })
                ->get();

            foreach ($reconciledSales as $entry) {
                app(DealerSalesLedgerReconciler::class)->restoreSalesOrderEntry($entry);
            }

            DealerTallyEntry::query()
                ->where('dealer_id', $dealer->id)
                ->where('source', TallyLedgerConfig::SOURCE)
                ->delete();
            DealerTallyImport::query()->where('dealer_id', $dealer->id)->delete();
            if (! DealerTallyEntry::query()->where('dealer_id', $dealer->id)->exists()) {
                DealerTallyLedger::query()->where('dealer_id', $dealer->id)->delete();
            }
        });

        Log::debug('tally_ledger_reset', [
            'dealer_id' => $dealer->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public function importPreview(array $preview, Dealer $dealer, User $actor, string $originalFilename): array
    {
        /** @var TallyLedgerParseResult $parsed */
        $parsed = $preview['parsed'] ?? null;
        if (! $parsed instanceof TallyLedgerParseResult) {
            throw ValidationException::withMessages([
                'file' => 'Upload the Tally Excel again before importing.',
            ]);
        }

        if (! $parsed->canImport()) {
            throw ValidationException::withMessages([
                'file' => $parsed->importErrors()[0] ?? 'Tally ledger parsing is incomplete. Import is blocked.',
            ]);
        }

        Log::debug('tally_ledger_import_selected_dealer', [
            'tally_ledger_name' => $parsed->tallyLedgerName,
            'selected_erp_dealer_id' => $dealer->id,
            'original_filename' => $originalFilename,
        ]);

        return DB::transaction(function () use ($preview, $parsed, $dealer, $actor, $originalFilename): array {
            $import = DealerTallyImport::query()->create([
                'dealer_id' => $dealer->id,
                'original_filename' => $originalFilename,
                'tally_ledger_name' => $parsed->tallyLedgerName !== '' ? $parsed->tallyLedgerName : null,
                'imported_by' => $actor->id,
                'imported_at' => Carbon::now('Asia/Kolkata'),
                'opening_balance' => $parsed->openingBalance,
                'opening_balance_type' => $parsed->openingBalanceType,
                'transaction_count' => count($parsed->transactions),
                'imported_count' => 0,
                'duplicate_count' => 0,
                'failed_count' => count($parsed->failed),
                'tally_closing_balance' => $parsed->tallyClosingBalance,
                'tally_closing_balance_type' => $parsed->tallyClosingBalanceType,
                'erp_closing_balance' => $preview['erp_closing_balance'] ?? 0,
                'erp_closing_balance_type' => $preview['erp_closing_balance_type'] ?? DealerTallyBalance::DEBIT,
                'balance_matched' => $preview['balance_matched'] ?? null,
                'difference' => $preview['difference'] ?? null,
                'status' => DealerTallyImport::STATUS_COMPLETED,
                'failed_rows' => $parsed->failed === [] ? null : $parsed->failed,
            ]);

            $imported = 0;
            $duplicates = 0;
            $reconciled = 0;
            $reconciler = app(DealerSalesLedgerReconciler::class);
            $reconciler->reconcileExistingDuplicates($dealer);

            foreach ($parsed->transactions as $transaction) {
                if ($this->isOpeningBalanceParticulars((string) ($transaction['particulars'] ?? ''))) {
                    continue;
                }

                $fingerprint = DealerTallyEntry::makeFingerprint(
                    dealerId: (int) $dealer->id,
                    date: $transaction['date'],
                    voucherType: $transaction['voucher_type'],
                    voucherNo: $transaction['voucher_no'],
                    debit: (float) $transaction['debit'],
                    credit: (float) $transaction['credit'],
                    particulars: $transaction['particulars'],
                );

                if ($reconciler->tallyDuplicateExists((int) $dealer->id, $transaction, $fingerprint)) {
                    $duplicates++;

                    continue;
                }

                if ($reconciler->isTallySalesDebit($transaction)) {
                    $salesOrder = $reconciler->findMatchingSalesOrderEntry(
                        dealerId: (int) $dealer->id,
                        debit: (float) $transaction['debit'],
                        tallyDate: (string) $transaction['date'],
                    );
                    if ($salesOrder !== null) {
                        $reconciler->reconcileSalesOrderWithTally($salesOrder, $transaction, (int) $import->id);
                        $reconciled++;

                        continue;
                    }
                }

                if ($this->shouldSkipNonSalesDuplicate($dealer, $transaction)) {
                    $duplicates++;

                    continue;
                }

                DealerTallyEntry::query()->create([
                    'dealer_id' => $dealer->id,
                    'import_id' => $import->id,
                    'entry_date' => $transaction['date'],
                    'particulars' => $transaction['particulars'],
                    'voucher_type' => $transaction['voucher_type'] !== '' ? $transaction['voucher_type'] : null,
                    'voucher_no' => $transaction['voucher_no'] !== '' ? $transaction['voucher_no'] : null,
                    'debit' => $transaction['debit'],
                    'credit' => $transaction['credit'],
                    'source' => TallyLedgerConfig::SOURCE,
                    'fingerprint' => $fingerprint,
                    'source_row' => $transaction['row_number'],
                ]);
                $imported++;
            }

            $this->replaceExistingOpeningBalance($dealer, $parsed);

            DealerTallyLedger::query()->updateOrCreate(
                ['dealer_id' => $dealer->id],
                array_merge([
                    'tally_closing_balance' => $parsed->tallyClosingBalance,
                    'tally_closing_balance_type' => $parsed->tallyClosingBalanceType,
                    'last_imported_at' => Carbon::now('Asia/Kolkata'),
                ], $this->importedOpeningPayload($parsed)),
            );

            $statement = $this->ledger->statement($dealer->fresh());
            $erpSigned = (float) $statement['summary']['current_outstanding_signed'];
            $tallySigned = $parsed->tallyClosingBalance !== null && $parsed->tallyClosingBalanceType !== null
                ? DealerTallyBalance::signed($parsed->tallyClosingBalance, $parsed->tallyClosingBalanceType)
                : null;
            $matched = $tallySigned !== null
                && DealerTallyBalance::matches(
                    DealerTallyBalance::amountFromSigned($erpSigned),
                    DealerTallyBalance::typeFromSigned($erpSigned),
                    $parsed->tallyClosingBalance,
                    $parsed->tallyClosingBalanceType,
                );

            $import->update([
                'imported_count' => $imported,
                'duplicate_count' => $duplicates + $reconciled,
                'erp_closing_balance' => DealerTallyBalance::amountFromSigned($erpSigned),
                'erp_closing_balance_type' => DealerTallyBalance::typeFromSigned($erpSigned),
                'balance_matched' => $tallySigned === null ? null : $matched,
                'difference' => $tallySigned === null ? null : round($erpSigned - $tallySigned, 2),
            ]);

            return [
                'import' => $import->fresh(),
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'reconciled_count' => $reconciled,
                'failed_count' => count($parsed->failed),
                'transaction_count' => count($parsed->transactions),
                'dealer' => $dealer,
                'summary' => $statement['summary'],
            ];
        });
    }

    /**
     * @return array{
     *     opening_balance: float,
     *     opening_balance_type: string,
     *     opening_balance_explicit: bool,
     *     financial_start_date: string
     * }
     */
    private function importedOpeningPayload(TallyLedgerParseResult $parsed): array
    {
        return [
            'opening_balance' => $parsed->openingBalance,
            'opening_balance_type' => $parsed->openingBalanceType,
            'opening_balance_explicit' => $parsed->openingBalanceExplicit,
            'financial_start_date' => TallyLedgerConfig::FINANCIAL_START_DATE,
        ];
    }

    /**
     * Latest Tally Excel is the only opening source. Missing or zero opening becomes ₹0.00.
     * Do not insert Opening Balance as a ledger transaction.
     */
    private function replaceExistingOpeningBalance(Dealer $dealer, TallyLedgerParseResult $parsed): void
    {
        $dealer->forceFill([
            'opening_balance' => $parsed->openingBalance,
            'opening_balance_type' => $parsed->openingBalanceType,
            'opening_balance_date' => TallyLedgerConfig::FINANCIAL_START_DATE,
        ])->save();

        DealerTallyEntry::query()
            ->where('dealer_id', $dealer->id)
            ->whereNotIn('source', [
                DealerTallyEntry::SOURCE_SALES_ORDER,
                DealerTallyEntry::SOURCE_COLLECTION,
            ])
            ->where(function ($query): void {
                $query->whereRaw('LOWER(COALESCE(particulars, \'\')) LIKE ?', ['%opening%balance%'])
                    ->orWhere('source', 'opening_balance');
            })
            ->delete();
    }

    private function isOpeningBalanceParticulars(string $particulars): bool
    {
        return preg_match('/opening\s*balance/i', $particulars) === 1;
    }

    /**
     * Skip non-sales Tally rows that already have the same dealer, date, debit and credit
     * (for example an ERP collection vs a Tally receipt). Do not modify those existing rows.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function shouldSkipNonSalesDuplicate(Dealer $dealer, array $transaction): bool
    {
        if (app(DealerSalesLedgerReconciler::class)->isTallySalesDebit($transaction)) {
            return false;
        }

        return app(DealerLedgerPostingService::class)->matchingSideExists(
            dealerId: (int) $dealer->id,
            date: (string) $transaction['date'],
            debit: (float) $transaction['debit'],
            credit: (float) $transaction['credit'],
        );
    }
}
