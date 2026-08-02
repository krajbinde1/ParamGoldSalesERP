<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finished Product Inventory master (1:1 with sales Product).
 *
 * Codes live here only. FG stock quantity / WAC remain on the linked Product
 * so production posting and sales catalog stay isolated.
 */
class FinishedProduct extends Model
{
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
