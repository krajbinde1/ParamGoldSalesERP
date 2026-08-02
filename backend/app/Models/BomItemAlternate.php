<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItemAlternate extends Model
{
    protected $attributes = [
        'conversion_ratio' => 1,
        'is_approved' => true,
        'priority' => 1,
    ];

    protected $fillable = [
        'bom_item_id',
        'item_type',
        'raw_material_id',
        'packaging_material_id',
        'conversion_ratio',
        'is_approved',
        'priority',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'conversion_ratio' => 'decimal:6',
            'is_approved' => 'boolean',
            'priority' => 'integer',
        ];
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

    public function materialName(): string
    {
        return (string) ($this->rawMaterial?->material_name
            ?? $this->packagingMaterial?->packaging_name
            ?? 'Alternate material');
    }
}
