<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DealerTallyEntry extends Model
{
    public const SOURCE_TALLY_IMPORT = 'tally_import';

    public const SOURCE_SALES_ORDER = 'sales_order';

    public const SOURCE_COLLECTION = 'collection';

    protected $fillable = [
        'dealer_id',
        'import_id',
        'entry_date',
        'particulars',
        'voucher_type',
        'voucher_no',
        'debit',
        'credit',
        'source',
        'source_id',
        'fingerprint',
        'source_row',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
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
}
