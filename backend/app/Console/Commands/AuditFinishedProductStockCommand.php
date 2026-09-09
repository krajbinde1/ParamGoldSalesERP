<?php

namespace App\Console\Commands;

use App\Services\Inventory\FinishedProductInventoryAuditService;
use Illuminate\Console\Command;

class AuditFinishedProductStockCommand extends Command
{
    protected $signature = 'inventory:audit-finished-product-stock
                            {--details : Print the full product/transaction reconciliation}';

    protected $description = 'Read-only Finished Product stock audit from 06-09-2026 (never writes)';

    public function handle(FinishedProductInventoryAuditService $audit): int
    {
        $report = $audit->report();

        $this->info('Finished Product stock audit (read-only)');
        $this->info('Reconciliation start: '.$report['reconciliation_start_date'].' (opening is start-of-day)');
        $this->newLine();
        $this->info('Total Products: '.$report['total_products']);
        $this->info('MATCH: '.$report['match']);
        $this->info('MISMATCH: '.$report['mismatch']);
        $this->info('NEGATIVE: '.$report['negative']);
        $this->info('Missing Dispatch Outwards: '.$report['missing_dispatch_outwards']);
        $this->info('Missing Inwards/Production: '.$report['missing_inwards_production']);

        if (! $this->option('details')) {
            $this->newLine();
            $this->comment('Re-run with --details for the product table and missing transaction lists.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Product reconciliation:');
        $this->table(
            [
                'Product',
                'Opening',
                'Inward/Production',
                'Adjustments/Returns',
                'Confirmed Dispatch',
                'Expected Stock',
                'ERP Stock',
                'Difference',
                'Status',
            ],
            array_map(fn (array $row): array => [
                $row['product_name'],
                $row['opening_qty'],
                $row['production_inward_qty'],
                $row['adjustments_returns_qty'],
                $row['confirmed_dispatch_qty'],
                $row['expected_current_stock'],
                $row['erp_current_stock'],
                $row['difference'],
                $row['status'],
            ], $report['product_effects']),
        );

        if ($report['missing_dispatch_lines'] !== []) {
            $this->newLine();
            $this->info('Missing dispatch outwards (included even if stock goes negative):');
            $this->table(
                ['Order ID', 'Order No', 'Dispatch Date', 'Product', 'Qty Nos', 'Expected After', 'Flag'],
                array_map(fn (array $row): array => [
                    $row['order_id'],
                    $row['order_no'],
                    $row['dispatch_date'],
                    $row['product_name'],
                    $row['qty_nos'],
                    $row['expected_stock_after'],
                    $row['review_flag'] ?? '',
                ], $report['missing_dispatch_lines']),
            );
        }

        if ($report['missing_production'] !== []) {
            $this->newLine();
            $this->warn('Missing production/inward ledgers (detectable completed batches):');
            $this->table(
                ['Batch ID', 'Batch No', 'Product', 'Qty', 'Production Date'],
                array_map(fn (array $row): array => [
                    $row['batch_id'],
                    $row['batch_number'],
                    $row['product_name'],
                    $row['qty'],
                    $row['production_date'] ?? '-',
                ], $report['missing_production']),
            );
        }

        $this->newLine();
        $this->warn('Read-only: no stock ledgers were written.');

        return self::SUCCESS;
    }
}
