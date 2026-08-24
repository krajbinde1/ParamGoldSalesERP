<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DealerTallyImport extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'dealer_id',
        'original_filename',
        'tally_ledger_name',
        'imported_by',
        'imported_at',
        'opening_balance',
        'opening_balance_type',
        'transaction_count',
        'imported_count',
        'duplicate_count',
        'failed_count',
        'tally_closing_balance',
        'tally_closing_balance_type',
        'erp_closing_balance',
        'erp_closing_balance_type',
        'balance_matched',
        'difference',
        'status',
        'failed_rows',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'opening_balance' => 'decimal:2',
            'tally_closing_balance' => 'decimal:2',
            'erp_closing_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'balance_matched' => 'boolean',
            'failed_rows' => 'array',
            'transaction_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function importedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DealerTallyEntry::class, 'import_id');
    }

    public function matchStatusLabel(): string
    {
        if ($this->tally_closing_balance === null || $this->tally_closing_balance_type === null) {
            return 'Tally closing not provided';
        }

        return $this->balance_matched ? 'Tally Balance Matched' : 'Tally Balance Mismatch';
    }
}
