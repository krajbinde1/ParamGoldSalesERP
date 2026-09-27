<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends Model
{
    protected $fillable = [
        'credit_note_id',
        'product_id',
        'case_quantity',
        'nos_per_case',
        'total_quantity_nos',
        'quantity',
        'rate',
        'rate_per_no',
        'rate_type',
        'original_rate',
        'revised_rate',
        'discount_percentage',
        'discount_amount',
        'gst_percentage',
        'base_amount',
        'taxable_amount',
        'gst_amount',
        'final_amount',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'rate' => 'decimal:2',
            'rate_per_no' => 'decimal:2',
            'original_rate' => 'decimal:2',
            'revised_rate' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'case_quantity' => 'integer',
            'nos_per_case' => 'integer',
            'total_quantity_nos' => 'integer',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
