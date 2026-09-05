<?php

namespace App\Models;

use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Models\Concerns\EnforcesSafeDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SemiFinishedMaterial extends Model
{
    use EnforcesSafeDelete;
    protected $attributes = [
        'opening_stock' => 0,
        'current_stock' => 0,
        'minimum_stock' => 0,
        'average_production_cost' => 0,
        'current_stock_value' => 0,
        'status' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (SemiFinishedMaterial $material): void {
            if (! filled($material->material_code)) {
                $material->material_code = app(\App\Services\Inventory\InventoryCodeGenerator::class)
                    ->nextSemiFinishedCode();
            }
        });
    }

    protected $fillable = [
        'material_code',
        'material_name',
        'unit',
        'opening_stock',
        'current_stock',
        'minimum_stock',
        'average_production_cost',
        'current_stock_value',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_stock' => 'decimal:3',
            'current_stock' => 'decimal:3',
            'minimum_stock' => 'decimal:3',
            'average_production_cost' => 'decimal:4',
            'current_stock_value' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'semi_finished_id');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class, 'semi_finished_id');
    }

    public function activeBom(): HasOne
    {
        return $this->hasOne(Bom::class, 'semi_finished_id')
            ->where('output_type', BomOutputType::SemiFinished)
            ->where('status', BomStatus::Active);
    }

    public function isLowStock(): bool
    {
        return (float) $this->current_stock > 0
            && (float) $this->current_stock <= (float) $this->minimum_stock;
    }

    public function isOutOfStock(): bool
    {
        return (float) $this->current_stock <= 0;
    }
}
