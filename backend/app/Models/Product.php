<?php

namespace App\Models;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'category' => 'General',
        'uom' => 'Piece',
        'nos_per_case' => 1,
        'manufacturing_enabled' => false,
        'current_finished_stock' => 0,
        'opening_finished_stock' => 0,
        'minimum_finished_stock' => 0,
        'standard_production_cost' => 0,
        'latest_production_cost' => 0,
        'weighted_average_cost' => 0,
        'batch_tracking_enabled' => true,
        'expiry_tracking_enabled' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (filled($product->product_code)) {
                return;
            }

            $lastCode = static::withTrashed()
                ->where('product_code', 'like', 'PRD%')
                ->orderByDesc('product_code')
                ->value('product_code');

            $nextNumber = $lastCode === null
                ? 1
                : ((int) substr($lastCode, 3)) + 1;

            $product->product_code = 'PRD'.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'product_code',
        'product_name',
        'category',
        'brand',
        'hsn_code',
        'uom',
        'nos_per_case',
        'pack_size',
        'gst_percentage',
        'mrp',
        'distributor_price',
        'dealer_price',
        'retail_price',
        'minimum_stock',
        'status',
        'manufacturing_enabled',
        'production_unit',
        'standard_batch_size',
        'current_finished_stock',
        'opening_finished_stock',
        'minimum_finished_stock',
        'standard_production_cost',
        'latest_production_cost',
        'weighted_average_cost',
        'shelf_life_days',
        'batch_tracking_enabled',
        'expiry_tracking_enabled',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'gst_percentage' => 'decimal:2',
            'mrp' => 'decimal:2',
            'distributor_price' => 'decimal:2',
            'dealer_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
            'nos_per_case' => 'integer',
            'status' => 'boolean',
            'manufacturing_enabled' => 'boolean',
            'standard_batch_size' => 'decimal:3',
            'current_finished_stock' => 'decimal:3',
            'opening_finished_stock' => 'decimal:3',
            'minimum_finished_stock' => 'decimal:3',
            'standard_production_cost' => 'decimal:4',
            'latest_production_cost' => 'decimal:4',
            'weighted_average_cost' => 'decimal:4',
            'shelf_life_days' => 'integer',
            'batch_tracking_enabled' => 'boolean',
            'expiry_tracking_enabled' => 'boolean',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function finishedProduct(): HasOne
    {
        return $this->hasOne(FinishedProduct::class);
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'product_id');
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->where('status', true)
            ->where('manufacturing_enabled', true)
            ->orderBy('pack_size');
    }

    public function boms(): HasMany
    {
        return $this->hasMany(Bom::class);
    }

    public function activeBom(): HasOne
    {
        return $this->hasOne(Bom::class)->where('status', 'active');
    }

    public function productionBatches(): HasMany
    {
        return $this->hasMany(ProductionBatch::class);
    }

    public function displayLabel(): string
    {
        $name = $this->product_name ?? $this->name ?? '';

        return trim(
            ($this->product_code ? $this->product_code.' — ' : '')
            .$name
        );
    }

    public function isConfiguredBulkProduct(): bool
    {
        $configured = config('inventory.bulk_product_ids', []);

        return in_array($this->id, array_map('intval', $configured), true);
    }

    public function isLowFinishedStock(): bool
    {
        return (float) $this->current_finished_stock > 0
            && (float) $this->current_finished_stock <= (float) $this->minimum_finished_stock;
    }

    public function isOutOfFinishedStock(): bool
    {
        return (float) $this->current_finished_stock <= 0;
    }

    public function hasFinishedStockTransactions(): bool
    {
        return $this->stockLedgers()->exists();
    }

    /**
     * Products eligible for Finished Product inventory (report / FG stock lists).
     * Includes manufacturing-enabled masters and any product that already holds FG stock
     * (e.g. produced before manufacturing_enabled was flipped on).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeInFinishedInventory($query)
    {
        return $query->where(function ($inner): void {
            $inner->where('manufacturing_enabled', true)
                ->orWhere('current_finished_stock', '>', 0);
        });
    }

    /**
     * Sales products eligible for Set Opening Stock (no opening balance posted yet).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeAvailableForFinishedProductLink($query)
    {
        return $query
            ->where('status', true)
            ->where(function ($inner): void {
                $inner->whereNull('opening_finished_stock')
                    ->orWhere('opening_finished_stock', '<=', 0);
            })
            ->whereDoesntHave('stockLedgers', function ($ledger): void {
                $ledger->where('item_type', StockItemType::FinishedProduct)
                    ->where('transaction_type', StockTransactionType::OpeningStock);
            });
    }

    /**
     * Finished-product stock aliases (Products module records ARE finished products).
     * Canonical columns remain current_finished_stock / weighted_average_cost.
     */
    public function getAverageProductionCostAttribute(): float
    {
        return (float) ($this->attributes['weighted_average_cost'] ?? 0);
    }

    public function getCurrentStockValueAttribute(): float
    {
        $stock = (float) ($this->attributes['current_finished_stock'] ?? 0);
        $avg = (float) ($this->attributes['weighted_average_cost'] ?? 0);

        return round($stock * $avg, 2);
    }

    /** @deprecated Prefer current_finished_stock; alias for finished-product inventory. */
    public function finishedCurrentStock(): float
    {
        return (float) ($this->attributes['current_finished_stock'] ?? 0);
    }
}
