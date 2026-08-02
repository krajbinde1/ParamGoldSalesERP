<?php

namespace App\Models;

use App\Enums\BomItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatchConsumption extends Model
{
    protected $fillable = [
        'production_batch_id',
        'bom_item_id',
        'item_type',
        'raw_material_id',
        'packaging_material_id',
        'semi_finished_id',
        'original_raw_material_id',
        'original_packaging_material_id',
        'material_name',
        'original_material_name',
        'unit',
        'formulation_quantity',
        'formulation_unit',
        'inventory_unit',
        'required_quantity',
        'standard_quantity',
        'consumed_quantity',
        'variance_quantity',
        'variance_percentage',
        'conversion_ratio',
        'stock_before',
        'stock_after',
        'rate',
        'consumption_value',
        'is_optional',
        'is_substituted',
        'substitution_reason',
        'substitution_remarks',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => BomItemType::class,
            'required_quantity' => 'decimal:4',
            'formulation_quantity' => 'decimal:6',
            'standard_quantity' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'variance_percentage' => 'decimal:3',
            'conversion_ratio' => 'decimal:6',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'rate' => 'decimal:4',
            'consumption_value' => 'decimal:2',
            'is_optional' => 'boolean',
            'is_substituted' => 'boolean',
        ];
    }

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function bomItem(): BelongsTo
    {
        return $this->belongsTo(BomItem::class);
    }

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }

    public function packagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class);
    }

    public function semiFinished(): BelongsTo
    {
        return $this->belongsTo(SemiFinishedMaterial::class, 'semi_finished_id');
    }

    public function originalRawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'original_raw_material_id');
    }

    public function originalPackagingMaterial(): BelongsTo
    {
        return $this->belongsTo(PackagingMaterial::class, 'original_packaging_material_id');
    }
}
