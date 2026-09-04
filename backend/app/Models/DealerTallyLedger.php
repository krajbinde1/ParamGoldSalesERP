<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerTallyLedger extends Model
{
    public const TYPE_DEBIT = 'debit';

    public const TYPE_CREDIT = 'credit';

    protected $fillable = [
        'dealer_id',
        'opening_balance',
        'opening_balance_type',
        'opening_balance_explicit',
        'financial_start_date',
        'tally_closing_balance',
        'tally_closing_balance_type',
        'last_imported_at',
        'live_closing_balance',
        'live_closing_balance_type',
        'live_tally_ledger_name',
        'live_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'opening_balance_explicit' => 'boolean',
            'financial_start_date' => 'date',
            'tally_closing_balance' => 'decimal:2',
            'last_imported_at' => 'datetime',
            'live_closing_balance' => 'decimal:2',
            'live_synced_at' => 'datetime',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function openingIsCredit(): bool
    {
        return strtolower((string) $this->opening_balance_type) === self::TYPE_CREDIT;
    }

    public function signedOpeningBalance(): float
    {
        $amount = round((float) $this->opening_balance, 2);

        return $this->openingIsCredit() ? -$amount : $amount;
    }
}
