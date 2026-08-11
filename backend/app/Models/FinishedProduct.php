<?php

namespace App\Models;

use App\Models\Concerns\EnforcesSafeDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Optional sidecar for legacy Finished Product (FP) codes (1:1 with sales Product).
 *
 * Sales Operations → Products is the single product master. FG stock quantity / WAC
 * live on Product; this row is kept non-destructively for BOM import compatibility.
 */
class FinishedProduct extends Model
{
    use EnforcesSafeDelete;
    protected $attributes = [
        'minimum_stock' => 0,
        'status' => true,
    ];

    protected $fillable = [
        'finished_product_code',
        'product_id',
        'unit',
        'minimum_stock',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:3',
            'status' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayLabel(): string
    {
        $name = $this->product?->product_name ?? '';

        return trim(
            ($this->finished_product_code ? $this->finished_product_code.' — ' : '')
            .$name
        );
    }

    public function currentStock(): float
    {
        return (float) ($this->product?->current_finished_stock ?? 0);
    }

    public function averageProductionCost(): float
    {
        return (float) ($this->product?->weighted_average_cost ?? 0);
    }

    public function currentStockValue(): float
    {
        return round($this->currentStock() * $this->averageProductionCost(), 2);
    }
}
