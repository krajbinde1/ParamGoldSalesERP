<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawMaterialInwardItem extends Model
{
    protected $fillable = [
        'raw_material_inward_id',
        'raw_material_id',
        'material_code',
        'material_name',
        'supplier_batch_number',
        'internal_batch_number',
        'manufacturing_date',
        'expiry_date',
        'received_quantity',
        'accepted_quantity',
        'rejected_quantity',
        'free_quantity',
        'unit',
        'stock_before',
        'stock_after',
        'basic_rate',
        'discount_percentage',
        'discount_amount',
        'freight_amount',
        'loading_unloading_amount',
        'other_charges',
        'taxable_amount',
        'gst_percentage',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_amount',
        'landed_cost',
        'effective_unit_rate',
        'old_average_rate',
        'new_average_rate',
        'remarks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'received_quantity' => 'decimal:3',
            'accepted_quantity' => 'decimal:3',
            'rejected_quantity' => 'decimal:3',
            'free_quantity' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'basic_rate' => 'decimal:4',
            'discount_percentage' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'loading_unloading_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'landed_cost' => 'decimal:2',
            'effective_unit_rate' => 'decimal:4',
            'old_average_rate' => 'decimal:4',
            'new_average_rate' => 'decimal:4',
        ];
    }

    public function inward(): BelongsTo
    {
        return $this->belongsTo(RawMaterialInward::class, 'raw_material_inward_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function batch(): HasOne
    {
        return $this->hasOne(RawMaterialBatch::class, 'inward_item_id');
    }
}
