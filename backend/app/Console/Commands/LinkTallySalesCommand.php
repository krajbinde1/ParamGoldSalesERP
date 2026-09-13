<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Services\Dealers\DealerSalesLedgerReconciler;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Support\IndianCurrency;
use Illuminate\Console\Command;

class LinkTallySalesCommand extends Command
{
    protected $signature = 'ledger:link-tally-sales
                            {--dealer= : Limit to a dealer id or firm-name fragment}
                            {--apply : Link definite duplicates (default is dry-run)}';

    protected $description = 'Classify and link ERP Sales Order ledger debits to matching Tally Sales vouchers';

    public function handle(
        DealerSalesLedgerReconciler $reconciler,
        TallyDealerLedgerService $ledger,
    ): int {
        $dealer = $this->resolveDealer();
        if ($this->option('dealer') && $dealer === null) {
            $this->error('No dealer matched ['.(string) $this->option('dealer').'].');

            return self::FAILURE;
        }

        $report = $reconciler->duplicateSalesReport($dealer);
        $definite = $report['pairs'];
        $ambiguous = $report['ambiguous'];
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'Applying definite ERP ↔ Tally sales links.' : 'Dry-run: no ledger rows will be deleted or changed.');
        $this->newLine();
        $this->info('Definite historical duplicates: '.$report['duplicate_count']);
        $this->info('Duplicate amount: '.IndianCurrency::formatExact($report['duplicate_amount']));
        $this->info('Ambiguous matches (left untouched): '.count($ambiguous));
        $this->info('Date window for amount fallback: '.DealerSalesLedgerReconciler::DATE_WINDOW_DAYS.' days');

        if ($dealer !== null && $report['current_debit_total'] !== null) {
            $this->info('Current Debit total: '.IndianCurrency::formatExact($report['current_debit_total']));
            $this->info('Correct Debit total: '.IndianCurrency::formatExact($report['correct_debit_total']));
            $this->info('Current outstanding: '.$report['current_outstanding_label']);
            $this->info('Correct outstanding: '.IndianCurrency::formatDrCr($report['correct_outstanding_signed']));
        }

        if ($definite !== []) {
            $this->newLine();
            $this->table(
                ['Dealer', 'ERP Order', 'ERP Amount', 'ERP Date', 'Tally Voucher No.', 'Tally Date', 'Tally Amount', 'Match reason'],
                array_map(fn (array $row): array => [
                    $row['dealer_name'],
                    $row['erp_order'],
                    number_format((float) $row['erp_amount'], 2, '.', ''),
                    $row['erp_date'] ?? '—',
                    $row['tally_voucher_no'] ?: '—',
                    $row['tally_date'] ?? '—',
                    number_format((float) $row['tally_amount'], 2, '.', ''),
                    $row['match_reason_label'],
                ], $definite),
            );
        }

        if ($ambiguous !== []) {
            $this->newLine();
            $this->warn('Ambiguous matches were not linked:');
            $this->table(
                ['Dealer', 'Reason', 'Amount', 'ERP vouchers', 'Tally vouchers'],
                array_map(fn (array $row): array => [
                    $row['dealer_name'],
                    $row['reason'],
                    number_format((float) $row['amount'], 2, '.', ''),
                    $row['erp_vouchers'],
                    $row['tally_vouchers'],
                ], $ambiguous),
            );
        }

        $linked = 0;
        if ($apply && $definite !== []) {
            $linked = $reconciler->reconcileExistingDuplicates($dealer);
            $this->newLine();
            $this->info('Linked definite pairs: '.$linked);
        } elseif (! $apply) {
            $this->newLine();
            $this->comment('Re-run with --apply to keep the original ERP Debit, stamp Tally voucher/GUID/date, and remove the duplicate Tally Import debit.');
        }

        $this->printRenukaClosing($ledger, $dealer);

        return self::SUCCESS;
    }

    private function resolveDealer(): ?Dealer
    {
        $needle = trim((string) $this->option('dealer'));
        if ($needle === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $needle) === 1) {
            return Dealer::query()->find((int) $needle);
        }

        return Dealer::query()
            ->where('firm_name', 'like', '%'.$needle.'%')
            ->orderBy('id')
            ->first();
    }

    private function printRenukaClosing(TallyDealerLedgerService $ledger, ?Dealer $scoped): void
    {
        $query = Dealer::query()->where('firm_name', 'like', '%Renuka Krushi%');
        if ($scoped !== null) {
            $query->whereKey($scoped->id);
        }

        $renuka = $query->orderBy('id')->first();
        if ($renuka === null) {
            return;
        }

        $signed = $ledger->signedCurrentOutstanding($renuka->fresh());
        $this->newLine();
        $this->info(sprintf(
            'Renuka Krushi Kendra closing: %s (expected ₹3,06,665.00 Dr)',
            IndianCurrency::formatDrCr($signed),
        ));
    }
}
