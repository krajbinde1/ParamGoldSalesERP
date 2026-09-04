<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Models\Order;
use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

class BackfillDealerLedgerEntriesCommand extends Command
{
    protected $signature = 'ledger:backfill-erp-entries
                            {--order=PG-0001 : Diagnose and post this order no (short or full, e.g. PG-0001)}
                            {--all : Post every billed/dispatched order (previous bulk behaviour)}
                            {--diagnose : Print skip reasons only; do not write ledger rows}';

    protected $description = 'Diagnose and post missing dealer ledger sales/collection entries';

    public function handle(DealerLedgerPostingService $posting): int
    {
        if ($this->option('all')) {
            if ($this->option('diagnose')) {
                $this->warn(' --diagnose with --all only prints PG-0001-style diagnostics; it does not bulk-write.');
                $this->diagnoseAndMaybePost($posting, (string) $this->option('order'), write: false);

                return self::SUCCESS;
            }

            $result = $posting->backfill();
            $this->info('Posted dispatched orders: '.$result['orders']);
            $this->info('Posted received collections: '.$result['collections']);
            $this->info('Reconciled Tally sales with ERP sales orders: '.$result['sales_reconciled']);
            $this->newLine();
            $this->diagnoseAndMaybePost($posting, (string) $this->option('order'), write: false);

            return self::SUCCESS;
        }

        $this->warn('Not running bulk backfill. Use --all to post every billed/dispatched order.');
        $this->diagnoseAndMaybePost(
            $posting,
            (string) $this->option('order'),
            write: ! $this->option('diagnose'),
        );

        return self::SUCCESS;
    }

    private function diagnoseAndMaybePost(DealerLedgerPostingService $posting, string $orderNo, bool $write): void
    {
        $expectedDealer = Dealer::query()
            ->where('firm_name', 'like', '%Amrut%Purna%')
            ->orderBy('id')
            ->first();

        $orders = $this->findOrders($orderNo, $expectedDealer);

        if ($orders->isEmpty()) {
            $this->error('No order matched '.$orderNo.'.');
            if ($expectedDealer !== null) {
                $this->line('Amrut Fertilizers Purna dealer_id='.$expectedDealer->id.' ('.$expectedDealer->firm_name.')');
            }

            return;
        }

        $rows = [];
        foreach ($orders as $order) {
            $diagnosis = $posting->diagnoseSalesOrder($order, $expectedDealer);
            $rows[] = [
                $diagnosis['order_id'],
                $diagnosis['order_no'],
                $diagnosis['dealer_id'] ?? 'null',
                $diagnosis['status'],
                number_format($diagnosis['grand_total'], 2, '.', ''),
                $diagnosis['existing_ledger_match'],
                $diagnosis['skip_reason'],
            ];

            $this->newLine();
            $this->info('--- Order '.$order->shortOrderNo().' (id '.$order->id.') ---');
            $this->line('DB id: '.$order->id);
            $this->line('order_no: '.$order->order_no.' (short '.$order->shortOrderNo().')');
            $this->line('dealer_id: '.($order->dealer_id ?? 'null').' ('.$diagnosis['dealer_name'].')');
            if ($expectedDealer !== null) {
                $this->line(
                    'Amrut Fertilizers Purna dealer_id: '.$expectedDealer->id
                    .' | match: '.((int) $order->dealer_id === (int) $expectedDealer->id ? 'YES' : 'NO')
                );
            }
            $this->line('status: '.$order->status);
            $this->line('grand_total: '.$diagnosis['grand_total']);
            $this->line('order_date: '.($diagnosis['order_date'] ?? 'null'));
            $this->line('created_at: '.($diagnosis['created_at'] ?? 'null'));
            $this->line('in backfill query: '.$diagnosis['in_backfill_query']);
            $this->line('existing ledger match: '.$diagnosis['existing_ledger_match']);
            $this->line('skip reason: '.$diagnosis['skip_reason']);

            if (! $write || $diagnosis['action'] === 'skip') {
                continue;
            }

            try {
                $posted = $posting->syncDispatchedOrder($order->fresh() ?? $order);
            } catch (Throwable $exception) {
                $this->error('POST FAILED: '.$exception->getMessage());
                $rows[count($rows) - 1][6] = 'POST FAILED: '.$exception->getMessage();

                continue;
            }

            if ($posted === null) {
                $this->error('syncDispatchedOrder returned null (see skip reason).');

                continue;
            }

            $this->info(sprintf(
                'POSTED ledger #%d | %s | Sales Order %s | debit %s | dealer_id %s | source=%s source_id=%s',
                $posted->id,
                $posted->entry_date?->toDateString() ?? 'null',
                $order->order_no,
                number_format((float) $posted->debit, 2, '.', ''),
                $posted->dealer_id,
                $posted->source,
                $posted->source_id ?? 'null',
            ));
        }

        $this->newLine();
        $this->table(
            ['Order ID', 'Order No', 'Dealer ID', 'Status', 'Grand Total', 'Existing Ledger Match', 'Skip Reason'],
            $rows,
        );
    }

    /**
     * @return EloquentCollection<int, Order>
     */
    private function findOrders(string $orderNo, ?Dealer $expectedDealer): EloquentCollection
    {
        $needle = strtoupper(trim($orderNo));

        if (preg_match('/^\d+$/', $needle) === 1) {
            $byId = Order::query()->withTrashed()->with('dealer')->find((int) $needle);
            if ($byId instanceof Order) {
                return new EloquentCollection([$byId]);
            }
        }

        $suffix = preg_replace('/^[A-Z]+-/', '', $needle) ?? $needle;

        $matched = Order::query()
            ->withTrashed()
            ->with('dealer')
            ->where(function ($query) use ($needle, $suffix): void {
                $query->whereRaw('UPPER(order_no) = ?', [$needle])
                    ->orWhereRaw('UPPER(order_no) LIKE ?', ['%-'.$suffix]);
            })
            ->orderBy('id')
            ->get()
            ->filter(function (Order $order) use ($needle): bool {
                return strtoupper((string) $order->order_no) === $needle
                    || strtoupper($order->shortOrderNo()) === $needle;
            })
            ->values();

        if ($matched->isNotEmpty()) {
            return new EloquentCollection($matched->all());
        }

        if ($needle !== 'PG-0001' || $expectedDealer === null) {
            return new EloquentCollection;
        }

        return Order::query()
            ->withTrashed()
            ->with('dealer')
            ->where('dealer_id', $expectedDealer->id)
            ->where(function ($query): void {
                $query->whereRaw('ABS(COALESCE(grand_total, 0) - 84525) < 0.005')
                    ->orWhereDate('order_date', '2026-08-31')
                    ->orWhere('order_no', 'like', '%0001%');
            })
            ->orderBy('id')
            ->get();
    }
}
