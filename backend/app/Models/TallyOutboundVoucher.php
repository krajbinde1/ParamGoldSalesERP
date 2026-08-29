<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TallyOutboundVoucher extends Model
{
    public const SOURCE_SALES_ORDER = 'sales_order';

    public const SOURCE_COLLECTION = 'collection';

    public const VOUCHER_SALES = 'Sales';

    public const VOUCHER_RECEIPT = 'Receipt';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'source_type',
        'source_id',
        'voucher_type',
        'erp_reference',
        'payload',
        'status',
        'attempts',
        'last_error',
        'claimed_at',
        'claimed_until',
        'claimed_by',
        'tally_voucher_no',
        'tally_master_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_id' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'claimed_until' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public static function salesReference(int $orderId): string
    {
        return 'ERP-SO-'.$orderId;
    }

    public static function receiptReference(int $collectionId): string
    {
        return 'ERP-COL-'.$collectionId;
    }

    public function scopeClaimable(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query->where(function (Builder $inner) use ($now): void {
            $inner->where('status', self::STATUS_PENDING)
                ->orWhere(function (Builder $claimed) use ($now): void {
                    $claimed->where('status', self::STATUS_CLAIMED)
                        ->where(function (Builder $expired) use ($now): void {
                            $expired->whereNull('claimed_until')
                                ->orWhere('claimed_until', '<=', $now);
                        });
                });
        });
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasBlockingClaim(?string $connectorId): bool
    {
        if ($this->status !== self::STATUS_CLAIMED) {
            return false;
        }

        if ($this->claimed_until === null || $this->claimed_until->lte(Carbon::now())) {
            return false;
        }

        if (filled($connectorId) && $this->claimed_by === $connectorId) {
            return false;
        }

        return true;
    }
}
