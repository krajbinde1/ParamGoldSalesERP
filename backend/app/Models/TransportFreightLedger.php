<?php

namespace App\Models;

use App\Enums\TransportFreightLedgerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportFreightLedger extends Model
{
    protected $fillable = [
        'transaction_date',
        'transaction_type',
        'purchase_id',
        'purchase_number',
        'supplier_id',
        'supplier_name',
        'transporter_name',
        'transport_invoice_lr_no',
        'amount',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'transaction_type' => TransportFreightLedgerType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return $this->transaction_type === TransportFreightLedgerType::Reversal
            ? -abs($amount)
            : abs($amount);
    }
}
