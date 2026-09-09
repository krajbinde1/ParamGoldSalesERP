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

    protected $description = 'Audit/post missing Finished Product dispatch outwards for dispatched sales orders (idempotent; never double-posts)';

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
        );

        $this->info('Dispatched orders scanned: '.$audit['scanned_orders']);
        $this->info('Already posted product lines: '.$audit['already_posted_lines']);
        $this->info('Ignored pre-opening product lines: '.$audit['ignored_pre_opening_lines']);
        $this->info('Missing product lines (on/after opening date): '.$audit['missing_lines']);
        $this->info('Blocked (insufficient stock): '.$audit['blocked_lines']);
        if ($audit['zero_qty_lines'] > 0) {
            $this->warn('Lines skipped for zero qty: '.$audit['zero_qty_lines']);
        }

        if ($audit['product_effects'] !== []) {
            $this->newLine();
            $this->info('Product-wise opening vs dispatch (opening is start-of-day; same-day dispatch is included):');
            $this->table(
                [
                    'Product',
                    'Opening Date',
                    'Opening Qty',
                    'Dispatch Before Opening (Ignore)',
                    'Dispatch On Opening Date (Backfill)',
                    'Dispatch After Opening Date (Backfill)',
                    'Total Valid Missing Outward',
                    'Expected Closing',
                ],
                array_map(fn (array $row): array => [
                    $row['product_name'],
                    $row['opening_date'] ?? '-',
                    $row['opening_qty'],
                    $row['ignore_before_qty'],
                    $row['on_opening_date_qty'],
                    $row['after_opening_qty'],
                    $row['missing_outward'],
                    $row['expected_closing'],
                ], $audit['product_effects']),
            );
        }

        if ($audit['ignored'] !== []) {
            $this->newLine();
            $this->warn('Ignored pre-opening dispatches (already inside opening stock):');
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
            $this->info('Missing dispatch outwards to backfill:');
            $this->table(
                ['Order ID', 'Order No', 'Dispatch Date', 'Period', 'Product ID', 'Product', 'Qty Nos', 'Stock Before', 'Expected After', 'Blocked'],
                array_map(fn (array $row): array => [
                    $row['order_id'],
                    $row['order_no'],
                    $row['dispatch_date'],
                    $row['period'],
                    $row['product_id'],
                    $row['product_name'],
                    $row['qty_nos'],
                    $row['stock_before'],
                    $row['expected_stock_after'],
                    $row['blocked'] ? ($row['block_reason'] ?? 'yes') : '',
                ], $audit['missing']),
            );
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry-run: no stock ledgers were written.');

            return self::SUCCESS;
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
