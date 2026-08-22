<?php

namespace App\Services\Dealers;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class DealerLedgerService
{
    private const TYPE_OPENING_BALANCE = 'opening_balance';

    private const TYPE_SALES_INVOICE = 'sales_invoice';

    private const TYPE_COLLECTION = 'collection';

    public function getOpeningBalance(Dealer $dealer): float
    {
        return $this->money($dealer->opening_balance);
    }

    public function getOpeningBalanceDate(Dealer $dealer): ?string
    {
        return $dealer->opening_balance_date?->toDateString();
    }

    public function getTotalBilledSales(Dealer $dealer): float
    {
        return $this->money(
            $dealer->orders()
                ->whereIn('status', Order::billedReceivableStatuses())
                ->sum('grand_total')
        );
    }

    public function getTotalCollections(Dealer $dealer): float
    {
        return $this->money(
            $dealer->collections()
                ->where('status', Collection::STATUS_RECEIVED)
                ->sum('amount')
        );
    }

    public function getTotalCreditNotes(Dealer $dealer): float
    {
        return 0.0;
    }

    public function getUnbilledOrders(Dealer $dealer): float
    {
        return $this->money(
            $dealer->orders()
                ->whereIn('status', Order::unbilledExposureStatuses())
                ->sum('grand_total')
        );
    }

    public function getOutstanding(Dealer $dealer): float
    {
        return $this->money(
            $this->getOpeningBalance($dealer)
            + $this->getTotalBilledSales($dealer)
            - $this->getTotalCollections($dealer)
            - $this->getTotalCreditNotes($dealer)
        );
    }

    public function getTotalExposure(Dealer $dealer): float
    {
        return $this->money($this->getOutstanding($dealer) + $this->getUnbilledOrders($dealer));
    }

    /**
     * @return array{
     *     dealer_id: int,
     *     dealer_code: string,
     *     dealer_name: string,
     *     opening_balance: float,
     *     opening_balance_date: ?string,
     *     billed_sales: float,
     *     collections_received: float,
     *     current_outstanding: float,
     *     unbilled_orders: float,
     *     total_exposure: float
     * }
     */
    public function getAccountSummary(Dealer $dealer): array
    {
        $openingBalance = $this->getOpeningBalance($dealer);
        $billedSales = $this->getTotalBilledSales($dealer);
        $collections = $this->getTotalCollections($dealer);
        $unbilled = $this->getUnbilledOrders($dealer);
        $outstanding = $this->money(
            $openingBalance + $billedSales - $collections - $this->getTotalCreditNotes($dealer)
        );

        return [
            'dealer_id' => (int) $dealer->id,
            'dealer_code' => (string) $dealer->dealer_code,
            'dealer_name' => (string) $dealer->firm_name,
            'opening_balance' => $openingBalance,
            'opening_balance_date' => $this->getOpeningBalanceDate($dealer),
            'billed_sales' => $billedSales,
            'collections_received' => $collections,
            'current_outstanding' => $outstanding,
            'unbilled_orders' => $unbilled,
            'total_exposure' => $this->money($outstanding + $unbilled),
        ];
    }

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     ledger: list<array<string, mixed>>
     * }
     */
    public function getLedger(Dealer $dealer): array
    {
        $summary = $this->getAccountSummary($dealer);
        $entries = [];
        $running = 0.0;

        $openingAmount = $summary['opening_balance'];
        $openingDate = $summary['opening_balance_date']
            ?? $dealer->created_at?->timezone('Asia/Kolkata')?->toDateString()
            ?? Carbon::now('Asia/Kolkata')->toDateString();

        $running = $this->money($openingAmount);
        $entries[] = $this->ledgerRow(
            date: $openingDate,
            type: self::TYPE_OPENING_BALANCE,
            particulars: 'Opening Balance',
            reference: null,
            debit: $openingAmount,
            credit: 0,
            balance: $running,
            statusRemark: $openingAmount == 0.0 ? 'No opening balance' : 'Opening Balance',
            sourceId: 0,
            sequence: 0,
        );

        $transactions = [];

        $dealer->orders()
            ->whereIn('status', Order::billedReceivableStatuses())
            ->orderBy('id')
            ->get(['id', 'order_no', 'bill_number', 'bill_date', 'billed_at', 'order_date', 'grand_total', 'status'])
            ->each(function (Order $order) use (&$transactions): void {
                $date = $order->bill_date?->toDateString()
                    ?? $order->billed_at?->timezone('Asia/Kolkata')?->toDateString()
                    ?? $order->order_date?->toDateString();

                $referenceParts = array_values(array_filter([
                    $order->order_no,
                    filled($order->bill_number) ? (string) $order->bill_number : null,
                ]));

                $transactions[] = [
                    'date' => $date ?? Carbon::now('Asia/Kolkata')->toDateString(),
                    'type' => self::TYPE_SALES_INVOICE,
                    'particulars' => 'Sales Invoice / Order Bill',
                    'reference' => $referenceParts === [] ? null : implode(' / ', $referenceParts),
                    'debit' => $this->money($order->grand_total),
                    'credit' => 0.0,
                    'status_remark' => Order::STATUS_LABELS[$order->status] ?? 'Billed',
                    'source_id' => (int) $order->id,
                    'sequence' => 1,
                ];
            });

        $dealer->collections()
            ->where('status', Collection::STATUS_RECEIVED)
            ->orderBy('id')
            ->get(['id', 'receipt_no', 'collection_date', 'amount', 'status'])
            ->each(function (Collection $collection) use (&$transactions): void {
                $transactions[] = [
                    'date' => $collection->collection_date?->toDateString()
                        ?? Carbon::now('Asia/Kolkata')->toDateString(),
                    'type' => self::TYPE_COLLECTION,
                    'particulars' => 'Payment Received / Collection',
                    'reference' => filled($collection->receipt_no)
                        ? (string) $collection->receipt_no
                        : 'COL-'.$collection->id,
                    'debit' => 0.0,
                    'credit' => $this->money($collection->amount),
                    'status_remark' => Collection::STATUS_LABELS[$collection->status] ?? 'Received',
                    'source_id' => (int) $collection->id,
                    'sequence' => 2,
                ];
            });

        usort($transactions, function (array $left, array $right): int {
            $dateCompare = strcmp((string) $left['date'], (string) $right['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $sequenceCompare = ((int) $left['sequence']) <=> ((int) $right['sequence']);
            if ($sequenceCompare !== 0) {
                return $sequenceCompare;
            }

            return ((int) $left['source_id']) <=> ((int) $right['source_id']);
        });

        foreach ($transactions as $transaction) {
            $running = $this->money(
                $running + (float) $transaction['debit'] - (float) $transaction['credit']
            );

            $entries[] = $this->ledgerRow(
                date: $transaction['date'],
                type: $transaction['type'],
                particulars: $transaction['particulars'],
                reference: $transaction['reference'],
                debit: (float) $transaction['debit'],
                credit: (float) $transaction['credit'],
                balance: $running,
                statusRemark: $transaction['status_remark'],
                sourceId: (int) $transaction['source_id'],
                sequence: (int) $transaction['sequence'],
            );
        }

        return [
            'summary' => $summary,
            'ledger' => $entries,
        ];
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    public function scopeWithCurrentOutstanding(Builder $query): Builder
    {
        $alias = 'current_outstanding';

        if (! collect($query->getQuery()->columns ?? [])->contains(fn ($column): bool => is_string($column) && str_contains($column, $alias))) {
            if ($query->getQuery()->columns === null) {
                $query->select($query->getModel()->getTable().'.*');
            }

            $query->selectRaw(self::currentOutstandingSql($query->getModel()->getTable()).' as '.$alias);
        }

        return $query;
    }

    public static function currentOutstandingSql(string $dealersTable = 'dealers'): string
    {
        $billed = collect(Order::billedReceivableStatuses())
            ->map(fn (string $status): string => "'".str_replace("'", "''", $status)."'")
            ->implode(',');
        $received = str_replace("'", "''", Collection::STATUS_RECEIVED);

        return "(
            COALESCE({$dealersTable}.opening_balance, 0)
            + COALESCE((
                SELECT SUM(orders.grand_total)
                FROM orders
                WHERE orders.dealer_id = {$dealersTable}.id
                  AND orders.deleted_at IS NULL
                  AND orders.status IN ({$billed})
            ), 0)
            - COALESCE((
                SELECT SUM(collections.amount)
                FROM collections
                WHERE collections.dealer_id = {$dealersTable}.id
                  AND collections.deleted_at IS NULL
                  AND collections.status = '{$received}'
            ), 0)
        )";
    }

    public function companyTotalOutstanding(): float
    {
        $opening = $this->money(Dealer::query()->sum('opening_balance'));
        $billed = $this->money(
            Order::query()
                ->whereIn('status', Order::billedReceivableStatuses())
                ->sum('grand_total')
        );
        $collections = $this->money(
            Collection::query()
                ->where('status', Collection::STATUS_RECEIVED)
                ->sum('amount')
        );

        return $this->money($opening + $billed - $collections);
    }

    public function highOutstandingDealerCount(): int
    {
        return (int) Dealer::query()
            ->where('status', true)
            ->where('credit_limit', '>', 0)
            ->whereRaw(self::currentOutstandingSql().' >= credit_limit * 0.9')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerRow(
        string $date,
        string $type,
        string $particulars,
        ?string $reference,
        float $debit,
        float $credit,
        float $balance,
        ?string $statusRemark,
        int $sourceId,
        int $sequence,
    ): array {
        return [
            'date' => $date,
            'type' => $type,
            'particulars' => $particulars,
            'reference' => $reference,
            'debit' => $this->money($debit),
            'credit' => $this->money($credit),
            'balance' => $this->money($balance),
            'status_remark' => $statusRemark,
            'source_id' => $sourceId,
            'sequence' => $sequence,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
