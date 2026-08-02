<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialInwardReturn extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'return_rate' => 0,
        'return_value' => 0,
    ];

    protected $fillable = [
        'return_number',
        'raw_material_inward_id',
        'raw_material_inward_item_id',
        'raw_material_id',
        'raw_material_batch_id',
        'return_date',
        'return_quantity',
        'reason',
        'supplier_credit_note_number',
        'remarks',
        'status',
        'return_rate',
        'return_value',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'return_quantity' => 'decimal:3',
            'return_rate' => 'decimal:4',
            'return_value' => 'decimal:2',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function inward(): BelongsTo
    {
        return $this->belongsTo(RawMaterialInward::class, 'raw_material_inward_id');
    }

    public function inwardItem(): BelongsTo
    {
        return $this->belongsTo(RawMaterialInwardItem::class, 'raw_material_inward_item_id');
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(RawMaterialBatch::class, 'raw_material_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
