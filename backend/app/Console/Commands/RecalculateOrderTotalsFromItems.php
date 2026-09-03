<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Console\Command;

class RecalculateOrderTotalsFromItems extends Command
{
    protected $signature = 'orders:recalculate-totals
                            {--dry-run : Report corrected totals without writing}
                            {--id= : Recalculate a single order id}';

    protected $description = 'Rebuild stored subtotal, GST, and grand total for every order from items and transport type';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orderId = $this->option('id');

        $query = Order::query()->with('items')->orderBy('id');
        if (filled($orderId)) {
            $query->whereKey((int) $orderId);
        }

        $updated = 0;
        $rows = [];

        $query->chunkById(50, function ($orders) use ($dryRun, &$updated, &$rows): void {
            foreach ($orders as $order) {
                $beforeStatus = $order->status;
                $beforeGrand = round((float) $order->grand_total, 2);
                $fill = OrderBillingTransportCalculator::correctedAttributes($order);

                if (! $dryRun) {
                    $order->forceFill($fill)->saveQuietly();
                    app(DealerLedgerPostingService::class)->syncDispatchedOrder($order);
                }

                $updated++;
                if (count($rows) < 25) {
                    $rows[] = [
                        $order->id,
                        $order->order_no,
                        $beforeStatus,
                        $order->transport_charge_type ?: $order->transport_type ?: '—',
                        number_format($beforeGrand, 2, '.', ''),
                        number_format((float) ($fill['grand_total'] ?? 0), 2, '.', ''),
                        number_format((float) ($fill['gst_amount'] ?? 0), 2, '.', ''),
                    ];
                }
            }
        });

        if ($rows !== []) {
            $this->table(
                ['ID', 'Order No', 'Status', 'Transport Type', 'Old Grand', 'New Grand', 'New GST'],
                $rows,
            );
        }

        $this->info(($dryRun ? '[dry-run] Would update ' : 'Updated ').$updated.' order(s).');

        return self::SUCCESS;
    }
}
