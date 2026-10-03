<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Models\DealerCreditLimit;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Single credit formula for order submit, preview APIs, and both UIs.
 *
 * Projected Credit Exposure = Current Ledger Outstanding + Eligible Pending Exposure + New Order Amount
 * Effective Limit = Base Limit + active temporary extension (valid through extension_valid_until, Asia/Kolkata)
 * Available = Effective Limit - (Current Ledger Outstanding + Eligible Pending Exposure)
 *
 * Pending exposure is orders that are placed or still committed but not yet in ledger outstanding:
 * pending_approval (placed), approved, on_hold, reverted_to_manager, pending_for_billing.
 * Those statuses are excluded from DealerLedgerPostingService, which posts a sales debit only when
 * the order is a billed receivable (billed, dispatched, delivered).
 *
 * Not pending, and not added again on top of outstanding:
 * billed, dispatched, delivered — already a ledger debit inside signed current outstanding.
 * credit_processed source credit notes — a ledger credit that already reduces outstanding.
 * rejected, cancelled, and draft — not a commitment.
 * Any order that already has a live sales_order dealer_tally_entries row is excluded so a billed
 * order cannot be counted twice.
 *
 * No base limit: status is No Limit Set and the order is not blocked.
 * A new order is blocked only when a limit is set and projected exposure reaches or exceeds the effective limit.
 */
final class DealerCreditExposureService
{
    public function __construct(
        private readonly DealerLedgerService $ledger,
    ) {}

    public function assess(Dealer $dealer, float $newOrderAmount = 0, ?int $excludeOrderId = null): DealerCreditAssessment
    {
        app(DealerCreditLimitWriter::class)->recordAutomaticExpiry($dealer);

        $profile = DealerCreditLimit::query()
            ->with('updatedBy:id,name')
            ->where('dealer_id', $dealer->id)
            ->first();

        return $this->calculate(
            dealerId: (int) $dealer->id,
            outstanding: $this->ledger->getOutstanding($dealer),
            pendingExposure: $this->pendingExposure($dealer, $excludeOrderId),
            profile: $profile,
            newOrderAmount: $newOrderAmount,
        );
    }

    /**
     * List pages pass outstanding and pending already selected on the dealer row.
     */
    public function fromLoadedDealer(Dealer $dealer, float $newOrderAmount = 0): DealerCreditAssessment
    {
        $outstanding = $dealer->getAttribute('current_outstanding');
        $pending = $dealer->getAttribute('pending_exposure');

        if ($outstanding === null || $pending === null) {
            return $this->assess($dealer, $newOrderAmount);
        }

        $profile = $dealer->relationLoaded('creditLimit')
            ? $dealer->creditLimit
            : $dealer->creditLimit()->with('updatedBy:id,name')->first();

        return $this->calculate(
            dealerId: (int) $dealer->id,
            outstanding: (float) $outstanding,
            pendingExposure: (float) $pending,
            profile: $profile,
            newOrderAmount: $newOrderAmount,
        );
    }

    public function pendingExposure(Dealer $dealer, ?int $excludeOrderId = null): float
    {
        $query = $dealer->orders()
            ->excludingCreditNoteSourceRecords()
            ->whereIn('status', Order::unbilledExposureStatuses())
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('dealer_tally_entries')
                    ->whereColumn('dealer_tally_entries.source_id', 'orders.id')
                    ->where('dealer_tally_entries.source', DealerTallyEntry::SOURCE_SALES_ORDER)
                    ->whereNull('dealer_tally_entries.removed_at');
            });

        if ($excludeOrderId !== null) {
            $query->where('orders.id', '!=', $excludeOrderId);
        }

        return $this->money($query->sum('grand_total'));
    }

    public function activeExtensionAmount(?DealerCreditLimit $profile, ?Carbon $at = null): float
    {
        if ($profile === null || $this->extensionIsExpired($profile, $at)) {
            return 0.0;
        }

        return $this->money($profile->extension_amount);
    }

    public function effectiveLimitAmount(?DealerCreditLimit $profile, ?Carbon $at = null): ?float
    {
        if ($profile === null || $profile->base_limit === null) {
            return null;
        }

        return $this->money((float) $profile->base_limit + $this->activeExtensionAmount($profile, $at));
    }

    public function extensionIsExpired(?DealerCreditLimit $profile, ?Carbon $at = null): bool
    {
        if ($profile === null) {
            return false;
        }

        if ($profile->extension_expired_at !== null) {
            return true;
        }

        $amount = $this->money($profile->extension_amount);
        if ($amount <= 0.0 || $profile->extension_valid_until === null) {
            return false;
        }

        $today = ($at ?? Carbon::now('Asia/Kolkata'))->timezone('Asia/Kolkata')->startOfDay();
        $until = $profile->extension_valid_until->timezone('Asia/Kolkata')->startOfDay();

        return $today->gt($until);
    }

    public function calculate(
        int $dealerId,
        float $outstanding,
        float $pendingExposure,
        ?DealerCreditLimit $profile,
        float $newOrderAmount,
    ): DealerCreditAssessment {
        $outstanding = $this->money($outstanding);
        $pendingExposure = $this->money($pendingExposure);
        $newOrderAmount = $this->money($newOrderAmount);
        $committed = $this->money($outstanding + $pendingExposure);
        $projected = $this->money($committed + $newOrderAmount);

        $base = $profile !== null && $profile->base_limit !== null
            ? $this->money($profile->base_limit)
            : null;
        $limitSet = $base !== null;
        $extensionActiveAmount = $this->activeExtensionAmount($profile);
        $extensionExpired = $profile !== null
            && $this->money($profile->extension_amount) > 0.0
            && $this->extensionIsExpired($profile);
        $effective = $limitSet ? $this->money($base + $extensionActiveAmount) : null;
        $available = $effective !== null ? $this->money($effective - $committed) : null;
        $utilization = null;

        if ($limitSet && $effective > 0) {
            $utilization = $committed <= 0
                ? 0.0
                : round(($committed / $effective) * 100, 2);
        }

        $exceededBy = 0.0;
        $blocks = false;
        $status = DealerCreditAssessment::STATUS_NO_LIMIT;
        $label = 'No Limit Set';

        if ($limitSet && $effective !== null) {
            $crosses = $newOrderAmount > 0.0 && $projected >= $effective;
            $exceededBy = $this->money(max(0, $projected - $effective));

            if ($crosses) {
                $status = DealerCreditAssessment::STATUS_ORDER_EXCEEDS;
                $label = 'Order Exceeds Limit';
                $blocks = true;
            } elseif ($available <= 0.0) {
                $status = DealerCreditAssessment::STATUS_LIMIT_REACHED;
                $label = 'Limit Reached';
            } elseif ($utilization !== null && $utilization >= DealerCreditAssessment::NEAR_UTILIZATION_PERCENT) {
                $status = DealerCreditAssessment::STATUS_NEAR_LIMIT;
                $label = 'Near Limit';
            } else {
                $status = DealerCreditAssessment::STATUS_SAFE;
                $label = 'Safe';
            }
        }

        return new DealerCreditAssessment(
            dealerId: $dealerId,
            limitSet: $limitSet,
            status: $status,
            statusLabel: $label,
            currentOutstanding: $outstanding,
            pendingExposure: $pendingExposure,
            newOrderAmount: $newOrderAmount,
            projectedExposure: $projected,
            baseLimit: $base,
            extensionAmount: $extensionActiveAmount,
            extensionActive: $extensionActiveAmount > 0.0,
            extensionExpired: $extensionExpired,
            extensionValidUntil: $profile?->extension_valid_until?->toDateString(),
            effectiveLimit: $effective,
            availableLimit: $available,
            utilizationPercent: $utilization,
            exceededBy: $exceededBy,
            blocksOrder: $blocks,
            lastUpdatedBy: $profile?->updatedBy?->name,
            lastUpdatedAt: $profile?->updated_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            pendingStatuses: Order::unbilledExposureStatuses(),
        );
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    public function scopeWithPendingExposure(Builder $query): Builder
    {
        $alias = 'pending_exposure';

        if (! collect($query->getQuery()->columns ?? [])->contains(
            fn ($column): bool => is_string($column) && str_contains($column, $alias)
        )) {
            if ($query->getQuery()->columns === null) {
                $query->select($query->getModel()->getTable().'.*');
            }

            $query->selectRaw(self::pendingExposureSql($query->getModel()->getTable()).' as '.$alias);
        }

        return $query;
    }

    public static function pendingExposureSql(string $dealersTable = 'dealers'): string
    {
        $statuses = implode(',', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            Order::unbilledExposureStatuses(),
        ));
        $sourceRole = str_replace("'", "''", Order::CREDIT_NOTE_LINK_SOURCE);
        $salesSource = str_replace("'", "''", DealerTallyEntry::SOURCE_SALES_ORDER);

        return "COALESCE((
            SELECT SUM(orders.grand_total)
            FROM orders
            WHERE orders.dealer_id = {$dealersTable}.id
              AND orders.deleted_at IS NULL
              AND orders.status IN ({$statuses})
              AND (orders.credit_note_link_role IS NULL OR orders.credit_note_link_role != '{$sourceRole}')
              AND NOT EXISTS (
                  SELECT 1 FROM dealer_tally_entries
                  WHERE dealer_tally_entries.source_id = orders.id
                    AND dealer_tally_entries.source = '{$salesSource}'
                    AND dealer_tally_entries.removed_at IS NULL
              )
        ), 0)";
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
