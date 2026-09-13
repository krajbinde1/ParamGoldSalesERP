<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DealerTallyEntry extends Model
{
    public const REMOVED_SCOPE = 'not_removed';

    public const SOURCE_TALLY_IMPORT = 'tally_import';

    public const SOURCE_TALLY_JOURNAL = 'tally_journal';

    public const SOURCE_SALES_ORDER = 'sales_order';

    public const SOURCE_COLLECTION = 'collection';

    public const SOURCE_LABEL_TALLY_JOURNAL = 'Tally - Journal/Adjustment';

    public const SALES_ENTRY_KEY = 'sales';

    private static bool $removedAtColumnReady = false;

    protected $fillable = [
        'dealer_id',
        'import_id',
        'entry_date',
        'particulars',
        'voucher_type',
        'voucher_no',
        'tally_voucher_type',
        'tally_voucher_no',
        'tally_entry_date',
        'tally_reconciled_at',
        'tally_voucher_guid',
        'tally_master_id',
        'tally_entry_key',
        'debit',
        'credit',
        'source',
        'source_id',
        'erp_reference',
        'fingerprint',
        'source_row',
        'removed_at',
        'removed_by',
        'removal_reason',
        'original_snapshot',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(self::REMOVED_SCOPE, function (Builder $builder): void {
            $model = $builder->getModel();
            if (! self::$removedAtColumnReady) {
                if (! Schema::hasColumn($model->getTable(), 'removed_at')) {
                    return;
                }
                self::$removedAtColumnReady = true;
            }

            $builder->whereNull($model->qualifyColumn('removed_at'));
        });
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'tally_entry_date' => 'date',
            'tally_reconciled_at' => 'datetime',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'source_id' => 'integer',
            'source_row' => 'integer',
            'removed_at' => 'datetime',
            'original_snapshot' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithRemoved(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::REMOVED_SCOPE);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnlyRemoved(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::REMOVED_SCOPE)
            ->whereNotNull($query->getModel()->qualifyColumn('removed_at'));
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DealerTallyImport::class, 'import_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public static function isRemovableSource(?string $source): bool
    {
        return in_array((string) $source, [
            self::SOURCE_TALLY_IMPORT,
            self::SOURCE_TALLY_JOURNAL,
        ], true);
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'id' => $this->id,
            'dealer_id' => $this->dealer_id,
            'import_id' => $this->import_id,
            'entry_date' => $this->entry_date?->toDateString(),
            'particulars' => $this->particulars,
            'voucher_type' => $this->voucher_type,
            'voucher_no' => $this->voucher_no,
            'tally_voucher_type' => $this->tally_voucher_type,
            'tally_voucher_no' => $this->tally_voucher_no,
            'tally_entry_date' => $this->tally_entry_date?->toDateString(),
            'tally_voucher_guid' => $this->tally_voucher_guid,
            'tally_master_id' => $this->tally_master_id,
            'tally_entry_key' => $this->tally_entry_key,
            'debit' => round((float) $this->debit, 2),
            'credit' => round((float) $this->credit, 2),
            'source' => $this->source,
            'source_id' => $this->source_id,
            'erp_reference' => $this->erp_reference,
            'fingerprint' => $this->fingerprint,
            'source_row' => $this->source_row,
        ];
    }

    public static function makeRemovedFingerprint(string $originalFingerprint, int $id): string
    {
        return hash('sha256', $originalFingerprint.'|removed|'.$id);
    }

    public static function makeFingerprint(
        int $dealerId,
        string $date,
        string $voucherType,
        string $voucherNo,
        float $debit,
        float $credit,
        string $particulars,
    ): string {
        $normalizedVoucherNo = Str::upper((string) preg_replace('/\s+/', '', $voucherNo));
        $normalizedType = Str::of($voucherType)->lower()->squish()->toString();
        $payload = implode('|', [
            $dealerId,
            $date,
            $normalizedType,
            $normalizedVoucherNo,
            number_format($debit, 2, '.', ''),
            number_format($credit, 2, '.', ''),
        ]);

        if ($normalizedVoucherNo === '') {
            $payload .= '|'.Str::of($particulars)->lower()->squish()->toString();
        }

        return hash('sha256', $payload);
    }

    public static function makeSourceFingerprint(string $source, int $sourceId): string
    {
        return hash('sha256', $source.'|'.$sourceId);
    }

    public static function salesErpReference(int $orderId): string
    {
        return 'ERP-SO-'.$orderId;
    }

    public static function makeJournalFingerprint(string $voucherGuid, string $entryKey): string
    {
        return hash('sha256', self::SOURCE_TALLY_JOURNAL.'|'.$voucherGuid.'|'.$entryKey);
    }

    public static function isOperationalTallyVoucherType(?string $voucherType): bool
    {
        $normalized = self::normalizedVoucherType($voucherType);
        if ($normalized === '') {
            return false;
        }

        foreach (['sales', 'receipt', 'payment', 'purchase', 'contra'] as $type) {
            if ($normalized === $type || str_starts_with($normalized, $type.' ')) {
                return true;
            }
        }

        return false;
    }

    public static function isJournalVoucherType(?string $voucherType): bool
    {
        $normalized = self::normalizedVoucherType($voucherType);
        if ($normalized === '' || self::isOperationalTallyVoucherType($voucherType)) {
            return false;
        }

        if ($normalized === 'journal' || str_starts_with($normalized, 'journal ')) {
            return true;
        }

        foreach (['bad debt', 'write off', 'writeoff', 'round off', 'roundoff', 'adjustment'] as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizedVoucherType(?string $voucherType): string
    {
        return Str::of((string) $voucherType)->lower()->replace(['_', '-'], ' ')->squish()->toString();
    }

    public static function sourceLabel(?string $source, ?string $voucherType = null): string
    {
        if ((string) $source === 'opening_balance') {
            return 'Opening Balance';
        }
        if ((string) $source === self::SOURCE_SALES_ORDER) {
            return 'ERP - Sales';
        }
        if ((string) $source === self::SOURCE_COLLECTION) {
            return 'ERP - Collection';
        }
        if ((string) $source === self::SOURCE_TALLY_JOURNAL || self::isJournalVoucherType($voucherType)) {
            return self::SOURCE_LABEL_TALLY_JOURNAL;
        }
        if ((string) $source === self::SOURCE_TALLY_IMPORT) {
            return 'Tally Import';
        }

        return '—';
    }
}
