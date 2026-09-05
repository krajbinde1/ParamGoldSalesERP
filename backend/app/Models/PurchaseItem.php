<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'raw_material_id',
        'packaging_material_id',
        'unit',
        'quantity',
        'purchase_rate',
        'taxable_amount',
        'gst_percentage',
        'gst_amount',
        'total_amount',
        'landed_cost',
        'effective_unit_rate',
        'batch_lot_no',
        'remarks',
        'stock_before',
        'stock_after',
        'old_average_rate',
        'new_average_rate',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'purchase_rate' => 'decimal:4',
            'taxable_amount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'landed_cost' => 'decimal:2',
            'effective_unit_rate' => 'decimal:4',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'old_average_rate' => 'decimal:4',
            'new_average_rate' => 'decimal:4',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function packagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class);
    }

    public function materialName(): string
    {
        return $this->rawMaterial?->material_name
            ?? $this->packagingMaterial?->packaging_name
            ?? '—';
    }
}
