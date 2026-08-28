<?php

namespace App\Services\Dealers;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Services\TallyLedger\TallyDealerLedgerService;
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

    public function getOpeningBalanceType(Dealer $dealer): string
    {
        return $dealer->openingBalanceIsCredit()
            ? Dealer::OPENING_BALANCE_CREDIT
            : Dealer::OPENING_BALANCE_DEBIT;
    }

    public function getSignedOpeningBalance(Dealer $dealer): float
    {
        return $this->money($dealer->signedOpeningBalance());
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
        return app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer);
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
     *     opening_balance_type: string,
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
        $statement = app(TallyDealerLedgerService::class)->statement($dealer);
        $outstanding = (float) $statement['summary']['current_outstanding_signed'];
        $unbilled = $this->getUnbilledOrders($dealer);

        return [
            'dealer_id' => (int) $dealer->id,
            'dealer_code' => (string) $dealer->dealer_code,
            'dealer_name' => (string) $dealer->firm_name,
            'opening_balance' => (float) $statement['summary']['opening_balance'],
            'opening_balance_type' => (string) $statement['summary']['opening_balance_type'],
            'opening_balance_date' => $statement['summary']['financial_start_date'],
            'billed_sales' => $this->getTotalBilledSales($dealer),
            'collections_received' => $this->getTotalCollections($dealer),
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
        $statement = app(TallyDealerLedgerService::class)->statement($dealer);
        $summary = $this->getAccountSummary($dealer);
        $entries = [];

        foreach ($statement['ledger'] as $index => $row) {
            $source = (string) ($row['source'] ?? '');
            $type = match (true) {
                (bool) ($row['is_opening'] ?? false) => self::TYPE_OPENING_BALANCE,
                $source === DealerTallyEntry::SOURCE_SALES_ORDER => self::TYPE_SALES_INVOICE,
                $source === DealerTallyEntry::SOURCE_COLLECTION => self::TYPE_COLLECTION,
                default => $source !== '' ? $source : 'ledger_entry',
            };

            $entries[] = $this->ledgerRow(
                date: (string) ($row['date'] ?? Carbon::now('Asia/Kolkata')->toDateString()),
                type: $type,
                particulars: (string) ($row['particulars'] ?? ''),
                reference: $row['voucher_no'] !== null && $row['voucher_no'] !== '' ? (string) $row['voucher_no'] : null,
                debit: (float) ($row['debit'] ?? 0),
                credit: (float) ($row['credit'] ?? 0),
                balance: (float) ($row['balance_signed'] ?? 0),
                statusRemark: $type === self::TYPE_OPENING_BALANCE ? 'Opening Balance' : null,
                sourceId: (int) ($row['source_id'] ?? 0),
                sequence: $index,
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

            $query->selectRaw(TallyDealerLedgerService::signedCurrentOutstandingSql($query->getModel()->getTable()).' as '.$alias);
        }

        return $query;
    }

    public static function signedOpeningBalanceSql(string $dealersTable = 'dealers'): string
    {
        $credit = str_replace("'", "''", Dealer::OPENING_BALANCE_CREDIT);

        return "(CASE
            WHEN LOWER(COALESCE({$dealersTable}.opening_balance_type, 'debit')) = '{$credit}'
            THEN -COALESCE({$dealersTable}.opening_balance, 0)
            ELSE COALESCE({$dealersTable}.opening_balance, 0)
        END)";
    }

    public static function currentOutstandingSql(string $dealersTable = 'dealers'): string
    {
        return TallyDealerLedgerService::signedCurrentOutstandingSql($dealersTable);
    }

    public function companyTotalOutstanding(): float
    {
        return app(DealerOutstandingService::class)->summary()['outstanding'];
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
