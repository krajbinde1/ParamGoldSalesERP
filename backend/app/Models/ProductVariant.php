<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductVariant extends Model
{
    protected $attributes = [
        'manufacturing_enabled' => true,
        'is_bulk' => false,
        'status' => true,
        'current_stock' => 0,
        'stock_unit' => 'Nos',
        'average_production_cost' => 0,
        'stock_value' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (filled($variant->variant_code)) {
                return;
            }

            $product = $variant->product ?? Product::query()->find($variant->product_id);
            $prefix = $product?->product_code ?? 'PRD';
            $count = static::query()->where('product_id', $variant->product_id)->count() + 1;
            $variant->variant_code = $prefix.'-P'.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
        });
    }

    protected $fillable = [
        'product_id',
        'variant_code',
        'pack_size',
        'pack_unit',
        'packaging_type',
        'units_per_case',
        'net_weight',
        'manufacturing_enabled',
        'is_bulk',
        'status',
        'current_stock',
        'stock_unit',
        'average_production_cost',
        'stock_value',
    ];

    protected function casts(): array
    {
        return [
            'pack_size' => 'decimal:4',
            'units_per_case' => 'integer',
            'net_weight' => 'decimal:4',
            'manufacturing_enabled' => 'boolean',
            'is_bulk' => 'boolean',
            'status' => 'boolean',
            'current_stock' => 'decimal:3',
            'average_production_cost' => 'decimal:4',
            'stock_value' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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

    public function label(): string
    {
        $size = rtrim(rtrim(number_format((float) $this->pack_size, 4, '.', ''), '0'), '.');

        return sprintf('%s — %s %s', $this->variant_code, $size, $this->pack_unit);
    }

    public function packLabel(): string
    {
        $size = rtrim(rtrim(number_format((float) $this->pack_size, 4, '.', ''), '0'), '.');

        if ($this->is_bulk || strcasecmp((string) $this->packaging_type, 'Bulk') === 0) {
            return 'Bulk';
        }

        $parts = ["{$size} {$this->pack_unit}"];
        if (filled($this->packaging_type)) {
            $parts[] = (string) $this->packaging_type;
        }

        return implode(' / ', $parts);
    }

    public function allowsBomWithoutStrictPack(): bool
    {
        return $this->is_bulk || strcasecmp((string) $this->packaging_type, 'Bulk') === 0;
    }

    public function finishedPacksFromOutput(float $outputQuantity): float
    {
        $packSize = (float) $this->pack_size;
        if ($packSize <= 0 || $this->allowsBomWithoutStrictPack()) {
            return round($outputQuantity, 3);
        }

        return round($outputQuantity / $packSize, 3);
    }
}
