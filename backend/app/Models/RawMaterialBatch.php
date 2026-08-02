<?php

namespace App\Models;

use App\Enums\RawMaterialBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterialBatch extends Model
{
    protected $attributes = [
        'status' => 'available',
        'received_quantity' => 0,
        'accepted_quantity' => 0,
        'available_quantity' => 0,
        'reserved_quantity' => 0,
        'consumed_quantity' => 0,
        'returned_quantity' => 0,
        'effective_unit_rate' => 0,
    ];

    protected $fillable = [
        'raw_material_id',
        'internal_batch_number',
        'supplier_batch_number',
        'inward_id',
        'inward_item_id',
        'manufacturing_date',
        'expiry_date',
        'received_quantity',
        'accepted_quantity',
        'available_quantity',
        'reserved_quantity',
        'consumed_quantity',
        'returned_quantity',
        'effective_unit_rate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date',
            'expiry_date' => 'date',
            'received_quantity' => 'decimal:3',
            'accepted_quantity' => 'decimal:3',
            'available_quantity' => 'decimal:3',
            'reserved_quantity' => 'decimal:3',
            'consumed_quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'effective_unit_rate' => 'decimal:4',
            'status' => RawMaterialBatchStatus::class,
        ];
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function inward(): BelongsTo
    {
        return $this->belongsTo(RawMaterialInward::class, 'inward_id');
    }

    public function inwardItem(): BelongsTo
    {
        return $this->belongsTo(RawMaterialInwardItem::class, 'inward_item_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(RawMaterialInwardReturn::class);
    }

    public function refreshStatus(): void
    {
        if ($this->status === RawMaterialBatchStatus::Blocked
            || $this->status === RawMaterialBatchStatus::Rejected) {
            return;
        }

        if ($this->expiry_date !== null && $this->expiry_date->isPast()) {
            $this->status = RawMaterialBatchStatus::Expired;
        } elseif ((float) $this->available_quantity <= 0) {
            $this->status = RawMaterialBatchStatus::Exhausted;
        } else {
            $minimum = (float) ($this->rawMaterial?->minimum_stock ?? 0);
            $this->status = $minimum > 0 && (float) $this->available_quantity <= $minimum
                ? RawMaterialBatchStatus::LowStock
                : RawMaterialBatchStatus::Available;
        }

        $this->save();
    }
}
