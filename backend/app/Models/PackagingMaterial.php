<?php

namespace App\Models;

use App\Enums\PackagingType;
use App\Models\Concerns\EnforcesSafeDelete;
use App\Services\Inventory\InventoryCodeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagingMaterial extends Model
{
    use EnforcesSafeDelete;

    protected $attributes = [
        'category' => 'Other',
        'packaging_type' => 'Other',
        'opening_stock' => 0,
        'current_stock' => 0,
        'minimum_stock' => 0,
        'purchase_rate' => 0,
        'average_rate' => 0,
        'current_stock_value' => 0,
        'batch_tracking_enabled' => false,
        'expiry_tracking_enabled' => false,
        'status' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (PackagingMaterial $material): void {
            if (! filled($material->packaging_code)) {
                $material->packaging_code = app(InventoryCodeGenerator::class)
                    ->nextPackagingMaterialCode();
            }

            if ($material->average_rate == 0 && $material->purchase_rate > 0) {
                $material->average_rate = $material->purchase_rate;
            }

            $material->current_stock = $material->opening_stock;
            $material->current_stock_value = round((float) $material->current_stock * (float) $material->average_rate, 2);
        });
    }

    protected $fillable = [
        'packaging_code',
        'packaging_name',
        'packaging_type',
        'category',
        'unit',
        'opening_stock',
        'current_stock',
        'minimum_stock',
        'purchase_rate',
        'average_rate',
        'current_stock_value',
        'batch_tracking_enabled',
        'expiry_tracking_enabled',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'packaging_type' => PackagingType::class,
            'opening_stock' => 'decimal:3',
            'current_stock' => 'decimal:3',
            'minimum_stock' => 'decimal:3',
            'purchase_rate' => 'decimal:4',
            'average_rate' => 'decimal:4',
            'current_stock_value' => 'decimal:2',
            'batch_tracking_enabled' => 'boolean',
            'expiry_tracking_enabled' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockLedgers(): HasMany
    {
        return $this->hasMany(StockLedger::class);
    }

    public function packagingTypeLabel(): string
    {
        $type = $this->packaging_type instanceof PackagingType
            ? $this->packaging_type
            : PackagingType::tryFromMixed($this->packaging_type);

        return $type?->label() ?? '—';
    }

    /**
     * Label used in BOM packaging-material selection.
     */
    public function bomSelectionLabel(): string
    {
        $name = trim((string) $this->packaging_name);
        $type = $this->packagingTypeLabel();

        if ($name === '') {
            return $type !== '—' ? $type : 'Packaging Material';
        }

        if ($type === '—') {
            return $name;
        }

        return $name.' — '.$type;
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
