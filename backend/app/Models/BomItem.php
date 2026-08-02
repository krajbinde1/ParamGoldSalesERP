<?php

namespace App\Models;

use App\Enums\BomItemType;
use App\Services\Inventory\InventoryUnitConversion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class BomItem extends Model
{
    protected $attributes = [
        'wastage_percentage' => 0,
        'calculated_quantity' => 0,
        'is_optional' => false,
        'sort_order' => 0,
        'conversion_factor' => 1,
    ];

    protected static function booted(): void
    {
        static::saving(function (BomItem $item): void {
            $item->wastage_percentage = 0;
            $item->recalculateInventoryEquivalent();
        });
    }

    protected $fillable = [
        'bom_id',
        'item_type',
        'raw_material_id',
        'packaging_material_id',
        'semi_finished_id',
        'required_quantity',
        'unit',
        'inventory_unit',
        'inventory_equivalent_quantity',
        'conversion_factor',
        'wastage_percentage',
        'calculated_quantity',
        'is_optional',
        'remarks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => BomItemType::class,
            'required_quantity' => 'decimal:4',
            'inventory_equivalent_quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:12',
            'wastage_percentage' => 'decimal:3',
            'calculated_quantity' => 'decimal:6',
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Recalculate inventory-equivalent qty from entered formulation qty/unit.
     * Always recomputes on the backend — never trust client-sent conversion fields alone.
     */
    public function recalculateInventoryEquivalent(): void
    {
        $converter = app(InventoryUnitConversion::class);
        $enteredQty = (float) ($this->required_quantity ?? 0);
        $enteredUnit = (string) ($this->unit ?? '');

        $inventoryUnit = $this->resolveMaterialInventoryUnit();
        if ($inventoryUnit === null || $inventoryUnit === '') {
            $inventoryUnit = $enteredUnit !== '' ? $converter->normalize($enteredUnit) : null;
        } else {
            $inventoryUnit = $converter->normalize($inventoryUnit);
        }

        $this->inventory_unit = $inventoryUnit;

        if ($enteredQty <= 0 || $enteredUnit === '' || $inventoryUnit === null || $inventoryUnit === '') {
            $this->conversion_factor = 1;
            $this->inventory_equivalent_quantity = $enteredQty;
            $this->calculated_quantity = $enteredQty;

            return;
        }

        try {
            $converted = $converter->convert($enteredQty, $enteredUnit, $inventoryUnit);
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->unit = $converted['from_unit'];
        $this->inventory_unit = $converted['to_unit'];
        $this->conversion_factor = $converted['conversion_factor'];
        $this->inventory_equivalent_quantity = $converted['quantity'];
        $this->calculated_quantity = $converted['quantity'];
    }

    public function resolveMaterialInventoryUnit(): ?string
    {
        $type = $this->item_type instanceof BomItemType
            ? $this->item_type
            : BomItemType::tryFrom((string) $this->item_type);

        if ($type === BomItemType::RawMaterial) {
            $material = $this->relationLoaded('rawMaterial')
                ? $this->rawMaterial
                : RawMaterial::query()->find($this->raw_material_id);

            return $material?->unit;
        }

        if ($type === BomItemType::PackagingMaterial) {
            $material = $this->relationLoaded('packagingMaterial')
                ? $this->packagingMaterial
                : PackagingMaterial::query()->find($this->packaging_material_id);

            return $material?->unit;
        }

        if ($type === BomItemType::SemiFinished) {
            $material = $this->relationLoaded('semiFinished')
                ? $this->semiFinished
                : SemiFinishedMaterial::query()->find($this->semi_finished_id);

            return $material?->unit;
        }

        return null;
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
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

    public function alternates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BomItemAlternate::class)->orderBy('priority');
    }

    public function approvedAlternates(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->alternates()->where('is_approved', true);
    }

    public function materialName(): string
    {
        return match ($this->item_type) {
            BomItemType::RawMaterial => (string) ($this->rawMaterial?->material_name ?? 'Raw Material'),
            BomItemType::PackagingMaterial => (string) ($this->packagingMaterial?->packaging_name ?? 'Packaging Material'),
            BomItemType::SemiFinished => (string) ($this->semiFinished?->material_name ?? 'Semi-Finished'),
        };
    }
}
