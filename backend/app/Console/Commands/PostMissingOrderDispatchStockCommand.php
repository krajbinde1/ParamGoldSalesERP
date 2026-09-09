<?php

namespace App\Console\Commands;

use App\Services\Inventory\OrderDispatchStockService;
use Illuminate\Console\Command;

class PostMissingOrderDispatchStockCommand extends Command
{
    protected $signature = 'inventory:post-missing-order-dispatch-stock
                            {order? : Specific sales order id}
                            {--all : Include every eligible dispatched order}
                            {--dry-run : Report missing outwards without writing stock ledgers}
                            {--product=* : Limit the report to these finished product names}';

    protected $description = 'Reconcile Finished Product stock from 06-09-2026 and post missing dispatch outwards (idempotent; never double-posts)';

    public function handle(OrderDispatchStockService $stock): int
    {
        $orderId = $this->argument('order');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');
        $productNames = array_values(array_filter((array) $this->option('product')));

        if (! $orderId && ! $all) {
            $this->error('Provide an order id or use --all.');

            return self::FAILURE;
        }

        $audit = $stock->auditMissing(
            $orderId !== null ? (int) $orderId : null,
            $productNames,
            includeAllFinishedProducts: $dryRun && $all,
        );

        if ($dryRun) {
            $this->info('Finished Product reconciliation from 06-09-2026 (opening is start-of-day; same-day dispatch is included).');
            $this->info('Pre-06-09-2026 dispatches are ignored. Historical confirmed dispatches are included even if stock goes negative.');
            $this->newLine();
            $this->table(
                [
                    'Product',
                    'Opening 06-09-2026',
                    'Inward/Production',
                    'Returns/+ Adj',
                    'Outward/- Adj',
                    'Confirmed Dispatch',
                    'Expected',
                    'ERP Current',
                    'Difference',
                    'Status',
                ],
                array_map(fn (array $row): array => [
                    $row['product_name'],
                    $row['opening_qty'],
                    $row['production_inward_qty'],
                    $row['returns_positive_qty'],
                    $row['outward_negative_adj_qty'],
                    $row['confirmed_dispatch_qty'],
                    $row['expected_current_stock'],
                    $row['erp_current_stock'],
                    $row['difference'],
                    $row['status'],
                ], $audit['product_effects']),
            );
            $this->newLine();
            $this->warn('Dry-run: no stock ledgers were written.');

            return self::SUCCESS;
        }

        $this->info('Reconciliation start date: '.$audit['reconciliation_start_date'].' (opening is start-of-day)');
        $this->info('Dispatched orders scanned: '.$audit['scanned_orders']);
        $this->info('Already posted product lines: '.$audit['already_posted_lines']);
        $this->info('Ignored pre-06-09-2026 product lines: '.$audit['ignored_pre_opening_lines']);
        $this->info('Missing product lines (on/after 06-09-2026): '.$audit['missing_lines']);
        $this->info('Missing lines that would make reconstructed stock negative (still included): '.$audit['negative_lines']);
        if ($audit['zero_qty_lines'] > 0) {
            $this->warn('Lines skipped for zero qty: '.$audit['zero_qty_lines']);
        }

        if ($audit['product_effects'] !== []) {
            $this->newLine();
            $this->info('Product-wise reconstruction from 06-09-2026:');
            $this->table(
                [
                    'Product',
                    'Opening 06-09-2026',
                    'Inward/Production',
                    'Returns/+ Adj',
                    'Outward/- Adj',
                    'Confirmed Dispatch',
                    'Expected',
                    'ERP Current',
                    'Difference',
                    'Status',
                ],
                array_map(fn (array $row): array => [
                    $row['product_name'],
                    $row['opening_qty'],
                    $row['production_inward_qty'],
                    $row['returns_positive_qty'],
                    $row['outward_negative_adj_qty'],
                    $row['confirmed_dispatch_qty'],
                    $row['expected_current_stock'],
                    $row['erp_current_stock'],
                    $row['difference'],
                    $row['status'],
                ], $audit['product_effects']),
            );
        }

        if ($audit['ignored'] !== []) {
            $this->newLine();
            $this->warn('Ignored pre-06-09-2026 dispatches (already inside opening stock):');
            $this->table(
                ['Order ID', 'Order No', 'Dispatch Date', 'Product', 'Qty Nos'],
                array_map(fn (array $row): array => [
                    $row['order_id'],
                    $row['order_no'],
                    $row['dispatch_date'],
                    $row['product_name'],
                    $row['qty_nos'],
                ], $audit['ignored']),
            );
        }

        if ($audit['missing'] !== []) {
            $this->newLine();
            $this->info('Missing dispatch outwards to reconcile (included even if stock goes negative):');
            $this->table(
                ['Order ID', 'Order No', 'Dispatch Date', 'Period', 'Product', 'Qty Nos', 'Stock Before', 'Expected After', 'Flag'],
                array_map(fn (array $row): array => [
                    $row['order_id'],
                    $row['order_no'],
                    $row['dispatch_date'],
                    $row['period'],
                    $row['product_name'],
                    $row['qty_nos'],
                    $row['stock_before'],
                    $row['expected_stock_after'],
                    $row['review_flag'] ?? '',
                ], $audit['missing']),
            );
        }

        if ($audit['missing_lines'] === 0) {
            $this->info('Nothing to post.');

            return self::SUCCESS;
        }

        $result = $stock->postMissingForDispatchedOrders(
            $orderId !== null ? (int) $orderId : null,
            $productNames,
        );

        $this->newLine();
        $this->info('Posted missing product lines: '.$result['posted']);
        $this->info('Skipped (already posted or ineligible): '.$result['skipped']);
        $this->info('Failed: '.$result['failed']);

        if ($result['failed_orders'] !== []) {
            $this->table(
                ['Order ID', 'Order No', 'Error'],
                array_map(fn (array $row): array => [
                    $row['order_id'],
                    $row['order_no'],
                    $row['error'],
                ], $result['failed_orders']),
            );
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
