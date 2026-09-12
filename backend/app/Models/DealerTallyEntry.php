<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DealerTallyEntry extends Model
{
    public const SOURCE_TALLY_IMPORT = 'tally_import';

    public const SOURCE_TALLY_JOURNAL = 'tally_journal';

    public const SOURCE_SALES_ORDER = 'sales_order';

    public const SOURCE_COLLECTION = 'collection';

    public const SOURCE_LABEL_TALLY_JOURNAL = 'Tally - Journal';

    public const SALES_ENTRY_KEY = 'sales';

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
    ];

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
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DealerTallyImport::class, 'import_id');
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

    public static function isJournalVoucherType(?string $voucherType): bool
    {
        $normalized = Str::of((string) $voucherType)->lower()->replace('_', ' ')->squish()->toString();

        return $normalized === 'journal' || str_starts_with($normalized, 'journal ');
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
