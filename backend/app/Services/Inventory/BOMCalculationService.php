<?php

namespace App\Services\Inventory;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\InventoryUnit;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use Illuminate\Validation\ValidationException;

final class BOMCalculationService
{
    /** @var array<int, true> */
    private array $computingSfFormulaRate = [];

    public function __construct(
        private readonly InventoryUnitConversion $unitConversion = new InventoryUnitConversion,
    ) {}

    public function getActiveBomForProduct(Product|int $product): ?Bom
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return Bom::query()
            ->with(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished', 'product', 'semiFinished'])
            ->where('output_type', BomOutputType::FinishedProduct)
            ->where('product_id', $productId)
            ->where('status', BomStatus::Active)
            ->first();
    }

    public function getActiveBomForSemiFinished(SemiFinishedMaterial|int $material): ?Bom
    {
        $materialId = $material instanceof SemiFinishedMaterial ? $material->id : $material;

        return Bom::query()
            ->with(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished', 'product', 'semiFinished'])
            ->where('output_type', BomOutputType::SemiFinished)
            ->where('semi_finished_id', $materialId)
            ->where('status', BomStatus::Active)
            ->first();
    }

    public function getActiveBomForOutput(string $outputType, int $outputId): ?Bom
    {
        if ($outputType === BomOutputType::SemiFinished->value) {
            return $this->getActiveBomForSemiFinished($outputId);
        }

        return $this->getActiveBomForProduct($outputId);
    }

    public function calculateItemQuantity(float $requiredQuantity, float $wastagePercentage): float
    {
        return round($requiredQuantity * (1 + ($wastagePercentage / 100)), 4);
    }

    /**
     * Scale BOM items for production, then convert formulation qty → inventory stock unit.
     *
     * 1) Scaled Formulation = (BOM Required ÷ Formula Qty) × Production Qty
     * 2) Inventory Deduction = convert(Scaled Formulation → material inventory unit)
     *
     * @return list<array<string, mixed>>
     */
    public function explodeRequirements(Bom $bom, float $plannedQuantity): array
    {
        if ($plannedQuantity <= 0) {
            throw ValidationException::withMessages([
                'planned_quantity' => 'Planned production quantity must be greater than zero.',
            ]);
        }

        $batchQty = (float) $bom->batch_quantity;
        if ($batchQty <= 0) {
            throw ValidationException::withMessages([
                'bom' => 'BOM Formula For Quantity must be greater than zero.',
            ]);
        }

        $bom->loadMissing(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished']);
        $this->assertBomStructure($bom, $bom->items, requireActivationRules: true);

        $scale = $plannedQuantity / $batchQty;
        $rows = [];

        foreach ($bom->items as $item) {
            /** @var BomItem $item */
            $material = $this->resolveMaterial($item);
            $inventoryUnit = $this->unitConversion->normalize(
                (string) ($item->inventory_unit ?: $material['unit'] ?? $item->unit)
            );
            $formulationUnit = $this->unitConversion->normalize((string) $item->unit);

            $scaledFormulation = round((float) $item->required_quantity * $scale, 6);
            $converted = $this->unitConversion->convert($scaledFormulation, $formulationUnit, $inventoryUnit);
            $inventoryRequired = (float) $converted['quantity'];
            $factor = (float) $converted['conversion_factor'];

            $available = (float) ($material['stock'] ?? 0);
            $rate = (float) ($material['rate'] ?? 0);
            $shortage = max(0, round($inventoryRequired - $available, 6));
            $minimum = (float) ($material['minimum'] ?? 0);
            $balanceAfter = round($available - $inventoryRequired, 6);

            $stockStatus = 'available';
            if ($shortage > 0 && ! $item->is_optional) {
                $stockStatus = 'shortage';
            } elseif ($available <= 0) {
                $stockStatus = 'out_of_stock';
            } elseif ($available <= $minimum) {
                $stockStatus = 'low_stock';
            }

            $rows[] = [
                'bom_item_id' => $item->id,
                'item_type' => $item->item_type->value,
                'raw_material_id' => $item->raw_material_id,
                'packaging_material_id' => $item->packaging_material_id,
                'semi_finished_id' => $item->semi_finished_id,
                'material_name' => $material['name'],
                // Formulation (scaled)
                'formulation_quantity' => $scaledFormulation,
                'formulation_unit' => $formulationUnit,
                // Inventory deduction (stock unit)
                'unit' => $inventoryUnit,
                'inventory_unit' => $inventoryUnit,
                'required_quantity' => $inventoryRequired,
                'inventory_equivalent_quantity' => $inventoryRequired,
                'conversion_factor' => $factor,
                'available_stock' => $available,
                'balance_after' => $balanceAfter,
                'shortage_quantity' => $shortage,
                'average_rate' => $rate,
                'estimated_value' => round($inventoryRequired * $rate, 2),
                'stock_status' => $stockStatus,
                'is_optional' => (bool) $item->is_optional,
                'minimum_stock' => $minimum,
            ];
        }

        return $rows;
    }

    /**
     * Quantity-wise BOM summary (no cross-unit raw-material totals).
     *
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array{
     *   formula_quantity: float,
     *   batch_unit: string,
     *   formula_unit: string,
     *   formula_for_label: string,
     *   total_items: int,
     *   raw_material_items: int,
     *   packaging_material_items: int,
     *   semi_finished_items: int,
     *   estimated_raw_material_cost: float,
     *   estimated_packaging_cost: float,
     *   estimated_semi_finished_cost: float,
     *   estimated_total_bom_cost: float,
     *   estimated_cost_per_finished_unit: float|null
     * }
     */
    public function summarizeBom(Bom|array $header, iterable $items): array
    {
        if ($header instanceof Bom) {
            $formulaQty = (float) $header->batch_quantity;
            $batchUnit = (string) $header->batch_unit;
        } else {
            $formulaQty = (float) ($header['batch_quantity'] ?? 0);
            $batchUnit = (string) ($header['batch_unit'] ?? '');
        }

        $totalItems = 0;
        $rawItems = 0;
        $packItems = 0;
        $sfItems = 0;
        $rawCost = 0.0;
        $packCost = 0.0;
        $sfCost = 0.0;

        foreach ($items as $item) {
            [$itemType, $qty, $unit, $rawId, $packId, $sfId] = $this->normalizeItemRow($item);

            if ($itemType === '' && $qty <= 0 && $rawId === null && $packId === null && $sfId === null) {
                continue;
            }

            $totalItems++;

            $inventoryUnit = $this->resolveInventoryUnitForRow($itemType, $rawId, $packId, $sfId, $item);
            $lineCost = 0.0;
            $rate = $this->resolveAverageRate($itemType, $rawId, $packId, $sfId, $item instanceof BomItem ? $item : null);

            if ($qty > 0 && $unit !== '' && $inventoryUnit !== null) {
                try {
                    $converted = $this->unitConversion->convert($qty, $unit, $inventoryUnit);
                    $lineCost = round((float) $converted['quantity'] * $rate, 4);
                } catch (ValidationException) {
                    $lineCost = 0.0;
                }
            }

            if ($itemType === BomItemType::RawMaterial->value) {
                $rawItems++;
                $rawCost += $lineCost;
            } elseif ($itemType === BomItemType::PackagingMaterial->value) {
                $packItems++;
                $packCost += $lineCost;
            } elseif ($itemType === BomItemType::SemiFinished->value) {
                $sfItems++;
                $sfCost += $lineCost;
            }
        }

        $totalCost = round($rawCost + $packCost + $sfCost, 4);
        $costPerUnit = $formulaQty > 0 ? round($totalCost / $formulaQty, 4) : null;

        $qtyLabel = $this->formatQuantity($formulaQty);
        $formulaForLabel = trim($qtyLabel.' '.($batchUnit !== '' ? $batchUnit : ''));

        return [
            'formula_quantity' => round($formulaQty, 4),
            'batch_unit' => $batchUnit,
            'formula_unit' => $batchUnit,
            'formula_for_label' => $formulaForLabel !== '' ? $formulaForLabel : '—',
            'total_items' => $totalItems,
            'raw_material_items' => $rawItems,
            'packaging_material_items' => $packItems,
            'semi_finished_items' => $sfItems,
            'estimated_raw_material_cost' => round($rawCost, 2),
            'estimated_packaging_cost' => round($packCost, 2),
            'estimated_semi_finished_cost' => round($sfCost, 2),
            'estimated_total_bom_cost' => round($totalCost, 2),
            'estimated_cost_per_finished_unit' => $costPerUnit !== null ? round($costPerUnit, 2) : null,
        ];
    }

    /**
     * @deprecated Use summarizeBom() — kept as alias for callers.
     *
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function summarizeFormula(Bom|array $header, iterable $items): array
    {
        return $this->summarizeBom($header, $items);
    }

    /**
     * Convert an item quantity into the BOM batch unit, or null if incompatible.
     * Retained for unit-conversion helpers; not used for formula-completion checks.
     */
    public function convertQuantityToBatchUnit(float $quantity, string $itemUnit, string $batchUnit): ?float
    {
        $item = InventoryUnit::tryFrom($itemUnit)?->value ?? $itemUnit;
        $batch = InventoryUnit::tryFrom($batchUnit)?->value ?? $batchUnit;

        return match ($batch) {
            InventoryUnit::Kg->value => match ($item) {
                InventoryUnit::Kg->value => $quantity,
                InventoryUnit::Gram->value => $quantity / 1000,
                default => null,
            },
            InventoryUnit::Litre->value => match ($item) {
                InventoryUnit::Litre->value => $quantity,
                InventoryUnit::Ml->value => $quantity / 1000,
                default => null,
            },
            InventoryUnit::Nos->value => $item === InventoryUnit::Nos->value ? $quantity : null,
            default => $item === $batch ? $quantity : null,
        };
    }

    /**
     * Validate BOM structure for save. Activation rules apply when status is Active.
     *
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function assertBomFormulaForSave(Bom|array $header, iterable $items, BomStatus|string|null $status): array
    {
        $statusEnum = $status instanceof BomStatus
            ? $status
            : BomStatus::tryFrom((string) $status);

        return $this->assertBomStructure(
            $header,
            $items,
            requireActivationRules: $statusEnum === BomStatus::Active,
        );
    }

    /**
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function assertBomStructure(Bom|array $header, iterable $items, bool $requireActivationRules = false): array
    {
        if ($header instanceof Bom) {
            $outputType = $header->output_type instanceof BomOutputType
                ? $header->output_type->value
                : (string) ($header->output_type ?? BomOutputType::FinishedProduct->value);
            $productId = $header->product_id;
            $semiFinishedId = $header->semi_finished_id;
            $formulaQty = (float) $header->batch_quantity;
            $batchUnit = (string) $header->batch_unit;
        } else {
            $rawOutput = $header['output_type'] ?? BomOutputType::FinishedProduct->value;
            $outputType = $rawOutput instanceof BomOutputType ? $rawOutput->value : (string) $rawOutput;
            $productId = $header['product_id'] ?? null;
            $semiFinishedId = $header['semi_finished_id'] ?? null;
            $formulaQty = (float) ($header['batch_quantity'] ?? 0);
            $batchUnit = (string) ($header['batch_unit'] ?? '');
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            $row = $this->normalizeItemRow($item);
            [$itemType, $qty, $unit, $rawId, $packId, $sfId] = $row;

            if ($itemType === '' && $qty <= 0 && $rawId === null && $packId === null && $sfId === null && $unit === '') {
                continue;
            }

            $normalizedItems[] = compact('itemType', 'qty', 'unit', 'rawId', 'packId', 'sfId');
        }

        if ($requireActivationRules) {
            if ($outputType === BomOutputType::SemiFinished->value) {
                if (blank($semiFinishedId)) {
                    throw ValidationException::withMessages([
                        'semi_finished_id' => 'Semi-finished output item is required before activating this BOM.',
                    ]);
                }
            } elseif (blank($productId)) {
                throw ValidationException::withMessages([
                    'product_id' => 'Product is required before activating this BOM.',
                ]);
            }

            if ($formulaQty <= 0) {
                throw ValidationException::withMessages([
                    'batch_quantity' => 'Formula For Quantity must be greater than zero before activating this BOM.',
                ]);
            }

            $normalizedBatchUnit = $this->unitConversion->normalize($batchUnit);
            if ($outputType === BomOutputType::FinishedProduct->value) {
                if (! in_array($normalizedBatchUnit, [InventoryUnit::Nos->value, InventoryUnit::Piece->value], true)) {
                    throw ValidationException::withMessages([
                        'batch_unit' => 'Packing BOMs (finished products) must use Nos or Piece. Each packing size is one SKU; put bulk quantity (e.g. 2 KG) on the Bulk / Semi-Finished formula line.',
                    ]);
                }
            } else {
                $allowedManufacturingUnits = [
                    InventoryUnit::Kg->value,
                    InventoryUnit::Litre->value,
                    InventoryUnit::Nos->value,
                    InventoryUnit::Piece->value,
                ];
                if (! in_array($normalizedBatchUnit, $allowedManufacturingUnits, true)) {
                    throw ValidationException::withMessages([
                        'batch_unit' => 'Manufacturing BOMs must use Kg, Litre, Nos, or Piece.',
                    ]);
                }

                $isCountUnit = in_array($normalizedBatchUnit, [
                    InventoryUnit::Nos->value,
                    InventoryUnit::Piece->value,
                ], true);
                $sfUnit = filled($semiFinishedId)
                    ? SemiFinishedMaterial::query()->whereKey($semiFinishedId)->value('unit')
                    : null;
                if (
                    ! $isCountUnit
                    && filled($sfUnit)
                    && ! $this->unitConversion->areCompatible($normalizedBatchUnit, (string) $sfUnit)
                ) {
                    throw ValidationException::withMessages([
                        'batch_unit' => 'Manufacturing BOM formula unit must match the bulk / semi-finished stock unit ('.$sfUnit.').',
                    ]);
                }
            }

            if ($normalizedItems === []) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one BOM item before activating this BOM.',
                ]);
            }
        }

        foreach ($normalizedItems as $index => $row) {
            $line = $index + 1;

            if ($row['itemType'] === BomItemType::RawMaterial->value) {
                if (blank($row['rawId'])) {
                    throw ValidationException::withMessages([
                        'items' => "BOM item #{$line}: select a raw material.",
                    ]);
                }
            } elseif ($row['itemType'] === BomItemType::PackagingMaterial->value) {
                if (blank($row['packId'])) {
                    throw ValidationException::withMessages([
                        'items' => "BOM item #{$line}: select a packaging material.",
                    ]);
                }
            } elseif ($row['itemType'] === BomItemType::SemiFinished->value) {
                if (blank($row['sfId'])) {
                    throw ValidationException::withMessages([
                        'items' => "BOM item #{$line}: select a semi-finished material.",
                    ]);
                }

                if (
                    $outputType === BomOutputType::SemiFinished->value
                    && filled($semiFinishedId)
                    && (int) $row['sfId'] === (int) $semiFinishedId
                ) {
                    throw ValidationException::withMessages([
                        'items' => "BOM item #{$line}: a semi-finished BOM cannot consume itself as an input.",
                    ]);
                }
            } else {
                throw ValidationException::withMessages([
                    'items' => "BOM item #{$line}: item type is required.",
                ]);
            }

            if ((float) $row['qty'] <= 0) {
                throw ValidationException::withMessages([
                    'items' => "BOM item #{$line}: Required Quantity must be greater than zero.",
                ]);
            }

            if (blank($row['unit'])) {
                throw ValidationException::withMessages([
                    'items' => "BOM item #{$line}: Formulation Unit is required.",
                ]);
            }

            $inventoryUnit = $this->resolveInventoryUnitForRow(
                $row['itemType'],
                $row['rawId'],
                $row['packId'],
                $row['sfId'],
                null,
            );

            if ($inventoryUnit === null || $inventoryUnit === '') {
                throw ValidationException::withMessages([
                    'items' => "BOM item #{$line}: material inventory unit could not be resolved.",
                ]);
            }

            if (! $this->unitConversion->areCompatible((string) $row['unit'], $inventoryUnit)) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        '%s cannot be converted to %s for this material.',
                        $row['unit'],
                        $inventoryUnit,
                    ),
                ]);
            }

            // Recalculate conversion on the backend (do not trust client values).
            $this->unitConversion->convert((float) $row['qty'], (string) $row['unit'], $inventoryUnit);
        }

        $this->assertManufacturingFormulaQuantityMatches($header, $items);

        return $this->summarizeBom($header, $items);
    }

    /**
     * Manufacturing (bulk / semi-finished) BOMs: raw + bulk ingredient quantity
     * converted to the formula unit must equal Formula Quantity exactly.
     * Packaging materials are excluded. Returns null when this rule does not apply.
     *
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array{
     *   applies: bool,
     *   matched: bool,
     *   formula_qty: float,
     *   added_qty: float,
     *   remaining: float,
     *   difference: float,
     *   unit: string,
     *   unit_label: string,
     *   formula_qty_label: string,
     *   added_qty_label: string,
     *   remaining_label: string,
     *   difference_label: string,
     *   message: string
     * }|null
     */
    public function manufacturingFormulaQuantityMatch(Bom|array $header, iterable $items): ?array
    {
        if ($header instanceof Bom) {
            $outputType = $header->output_type instanceof BomOutputType
                ? $header->output_type->value
                : (string) ($header->output_type ?? '');
            $formulaQty = (float) $header->batch_quantity;
            $batchUnit = (string) $header->batch_unit;
        } else {
            $rawOutput = $header['output_type'] ?? '';
            $outputType = $rawOutput instanceof BomOutputType ? $rawOutput->value : (string) $rawOutput;
            $formulaQty = (float) ($header['batch_quantity'] ?? 0);
            $batchUnit = (string) ($header['batch_unit'] ?? '');
        }

        if ($outputType !== BomOutputType::SemiFinished->value) {
            return null;
        }

        $formulaUnit = $this->unitConversion->normalize($batchUnit);
        $family = $this->unitConversion->family($formulaUnit);
        if (! in_array($family, [
            InventoryUnitConversion::FAMILY_WEIGHT,
            InventoryUnitConversion::FAMILY_VOLUME,
        ], true)) {
            return null;
        }

        $added = 0.0;
        foreach ($items as $item) {
            $converted = $this->productionIngredientQtyInFormulaUnit($item, $formulaUnit);
            if ($converted !== null) {
                $added += $converted;
            }
        }

        $formulaQty = round($formulaQty, 4);
        $added = round($added, 4);
        $remaining = round($formulaQty - $added, 4);
        $difference = round(abs($remaining), 4);
        $matched = $difference < 0.0001;
        $unitLabel = InventoryUnit::tryFromMixed($formulaUnit)?->formulaShortName() ?? $formulaUnit;
        $formulaLabel = $this->formatFormulaMatchQuantity($formulaQty);
        $addedLabel = $this->formatFormulaMatchQuantity($added);
        $remainingLabel = $this->formatFormulaMatchQuantity($remaining);
        $differenceLabel = $this->formatFormulaMatchQuantity($difference);

        return [
            'applies' => true,
            'matched' => $matched,
            'formula_qty' => $formulaQty,
            'added_qty' => $added,
            'remaining' => $remaining,
            'difference' => $difference,
            'unit' => $formulaUnit,
            'unit_label' => $unitLabel,
            'formula_qty_label' => $formulaLabel,
            'added_qty_label' => $addedLabel,
            'remaining_label' => $remainingLabel,
            'difference_label' => $differenceLabel,
            'message' => sprintf(
                'Formula quantity mismatch. Required: %s %s | Added: %s %s | Difference: %s %s',
                $formulaLabel,
                $unitLabel,
                $addedLabel,
                $unitLabel,
                $differenceLabel,
                $unitLabel,
            ),
        ];
    }

    public function assertManufacturingFormulaQuantityMatches(Bom|array $header, iterable $items): void
    {
        $match = $this->manufacturingFormulaQuantityMatch($header, $items);
        if ($match === null || $match['matched']) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => $match['message'],
        ]);
    }

    /**
     * @deprecated Quantity-match checks removed; validates quantity-wise BOM structure.
     *
     * @param  Bom|array<string, mixed>  $header
     * @param  iterable<int|string, BomItem|array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function assertBomFormula(Bom|array $header, iterable $items, bool $requireComplete = false): array
    {
        return $this->assertBomStructure($header, $items, requireActivationRules: $requireComplete);
    }

    public function assertActiveBomFormulaIsComplete(Bom $bom): void
    {
        $bom->loadMissing('items');
        $this->assertBomStructure($bom, $bom->items, requireActivationRules: true);
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    public function assertMandatoryStockAvailable(array $requirements): void
    {
        $shortages = [];

        foreach ($requirements as $row) {
            if (($row['is_optional'] ?? false) === true) {
                continue;
            }

            if (($row['shortage_quantity'] ?? 0) > 0) {
                $formulation = isset($row['formulation_quantity'], $row['formulation_unit'])
                    ? sprintf('%s %s', $row['formulation_quantity'], $row['formulation_unit'])
                    : null;

                $shortages[] = sprintf(
                    '%s: required %s %s%s, available %s %s (shortage %s %s)',
                    $row['material_name'],
                    $row['required_quantity'],
                    $row['inventory_unit'] ?? $row['unit'] ?? '',
                    $formulation ? " (equiv. {$formulation})" : '',
                    $row['available_stock'],
                    $row['inventory_unit'] ?? $row['unit'] ?? '',
                    $row['shortage_quantity'],
                    $row['inventory_unit'] ?? $row['unit'] ?? '',
                );
            }
        }

        if ($shortages !== []) {
            throw ValidationException::withMessages([
                'stock' => 'Insufficient mandatory material stock: '.implode('; ', $shortages),
            ]);
        }
    }

    public function syncCalculatedQuantities(Bom $bom): void
    {
        foreach ($bom->items as $item) {
            $item->wastage_percentage = 0;
            $item->recalculateInventoryEquivalent();
            $item->save();
        }
    }

    /**
     * Application-level uniqueness: only one Active BOM per product.
     */
    public function ensureSingleActiveBom(Bom $bom): void
    {
        if ($bom->status !== BomStatus::Active) {
            return;
        }

        $this->assertBomCanBeActivated($bom);

        $query = Bom::query()
            ->where('id', '!=', $bom->id)
            ->where('status', BomStatus::Active)
            ->where('output_type', $bom->output_type instanceof BomOutputType
                ? $bom->output_type->value
                : (string) $bom->output_type);

        if ($bom->output_type === BomOutputType::SemiFinished) {
            $query->where('semi_finished_id', $bom->semi_finished_id);
        } else {
            $query->where('product_id', $bom->product_id);
        }

        $query->update(['status' => BomStatus::Inactive->value]);
    }

    public function assertBomCanBeActivated(Bom $bom): void
    {
        if ($bom->status !== BomStatus::Active) {
            return;
        }

        $bom->loadMissing(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished']);
        $this->assertBomStructure($bom, $bom->items, requireActivationRules: true);
    }

    public function isValidRawMaterialUnit(string $unit): bool
    {
        $family = $this->unitConversion->family($unit);

        return in_array($family, [
            InventoryUnitConversion::FAMILY_WEIGHT,
            InventoryUnitConversion::FAMILY_VOLUME,
            InventoryUnitConversion::FAMILY_COUNT,
        ], true);
    }

    public function isValidPackagingUnit(string $unit): bool
    {
        return $this->isValidRawMaterialUnit($unit);
    }

    /**
     * @return array{0: string, 1: float, 2: string, 3: ?int, 4: ?int, 5: ?int}
     */
    private function normalizeItemRow(BomItem|array $item): array
    {
        if ($item instanceof BomItem) {
            $type = $item->item_type instanceof BomItemType
                ? $item->item_type->value
                : (string) $item->item_type;

            return [
                $type,
                (float) $item->required_quantity,
                (string) $item->unit,
                $item->raw_material_id !== null ? (int) $item->raw_material_id : null,
                $item->packaging_material_id !== null ? (int) $item->packaging_material_id : null,
                $item->semi_finished_id !== null ? (int) $item->semi_finished_id : null,
            ];
        }

        $rawType = $item['item_type'] ?? '';
        $type = $rawType instanceof BomItemType ? $rawType->value : (string) $rawType;
        $rawId = $item['raw_material_id'] ?? null;
        $packId = $item['packaging_material_id'] ?? null;
        $sfId = $item['semi_finished_id'] ?? null;

        return [
            $type,
            (float) ($item['required_quantity'] ?? 0),
            (string) ($item['unit'] ?? ''),
            $rawId !== null && $rawId !== '' ? (int) $rawId : null,
            $packId !== null && $packId !== '' ? (int) $packId : null,
            $sfId !== null && $sfId !== '' ? (int) $sfId : null,
        ];
    }

    private function resolveInventoryUnitForRow(
        string $itemType,
        ?int $rawId,
        ?int $packId,
        ?int $sfId,
        BomItem|array|null $item,
    ): ?string {
        if ($item instanceof BomItem && filled($item->inventory_unit)) {
            return $this->unitConversion->normalize((string) $item->inventory_unit);
        }

        if (is_array($item) && filled($item['inventory_unit'] ?? null)) {
            return $this->unitConversion->normalize((string) $item['inventory_unit']);
        }

        if ($itemType === BomItemType::RawMaterial->value && $rawId !== null) {
            $unit = RawMaterial::query()->whereKey($rawId)->value('unit');

            return $unit !== null ? $this->unitConversion->normalize((string) $unit) : null;
        }

        if ($itemType === BomItemType::PackagingMaterial->value && $packId !== null) {
            $unit = PackagingMaterial::query()->whereKey($packId)->value('unit');

            return $unit !== null ? $this->unitConversion->normalize((string) $unit) : null;
        }

        if ($itemType === BomItemType::SemiFinished->value && $sfId !== null) {
            $unit = SemiFinishedMaterial::query()->whereKey($sfId)->value('unit');

            return $unit !== null ? $this->unitConversion->normalize((string) $unit) : null;
        }

        return null;
    }

    private function resolveAverageRate(string $itemType, ?int $rawId, ?int $packId, ?int $sfId = null, ?BomItem $item = null): float
    {
        if ($itemType === BomItemType::RawMaterial->value && $rawId !== null) {
            if ($item?->relationLoaded('rawMaterial')) {
                return (float) ($item->rawMaterial?->average_rate ?? 0);
            }

            return (float) (RawMaterial::query()->whereKey($rawId)->value('average_rate') ?? 0);
        }

        if ($itemType === BomItemType::PackagingMaterial->value && $packId !== null) {
            if ($item?->relationLoaded('packagingMaterial')) {
                return (float) ($item->packagingMaterial?->average_rate ?? 0);
            }

            return (float) (PackagingMaterial::query()->whereKey($packId)->value('average_rate') ?? 0);
        }

        if ($itemType === BomItemType::SemiFinished->value && $sfId !== null) {
            $material = $item?->relationLoaded('semiFinished')
                ? $item->semiFinished
                : SemiFinishedMaterial::query()->find($sfId);

            return $this->semiFinishedEstimatedRate((int) $sfId, $material);
        }

        return 0.0;
    }

    private function formatQuantity(float $quantity): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Display helper for manufacturing formula match (200, 197.60, 2.40).
     */
    public function formatFormulaMatchQuantity(float $quantity): string
    {
        $formatted = number_format(round($quantity, 2), 2, '.', '');

        if (str_ends_with($formatted, '.00')) {
            return substr($formatted, 0, -3);
        }

        return $formatted;
    }

    /**
     * Convert a raw / bulk ingredient to the manufacturing formula unit.
     * Uses inventory equivalent first, then formula unit. Packaging is ignored.
     */
    private function productionIngredientQtyInFormulaUnit(BomItem|array $item, string $formulaUnit): ?float
    {
        [$itemType, $qty, $unit, $rawId, $packId, $sfId] = $this->normalizeItemRow($item);

        if (! in_array($itemType, [
            BomItemType::RawMaterial->value,
            BomItemType::SemiFinished->value,
        ], true)) {
            return null;
        }

        if ($qty <= 0 || $unit === '') {
            return 0.0;
        }

        $inventoryUnit = $this->resolveInventoryUnitForRow($itemType, $rawId, $packId, $sfId, $item);

        try {
            if ($inventoryUnit !== null && $inventoryUnit !== '') {
                $inventoryQty = (float) $this->unitConversion->convert($qty, $unit, $inventoryUnit)['quantity'];

                return (float) $this->unitConversion->convert($inventoryQty, $inventoryUnit, $formulaUnit)['quantity'];
            }

            return (float) $this->unitConversion->convert($qty, $unit, $formulaUnit)['quantity'];
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * @return array{name: string, stock: float, rate: float, minimum: float, unit: string}
     */
    private function resolveMaterial(BomItem $item): array
    {
        if ($item->item_type === BomItemType::RawMaterial) {
            $material = $item->rawMaterial ?? RawMaterial::query()->find($item->raw_material_id);

            return [
                'name' => (string) ($material?->material_name ?? 'Unknown raw material'),
                'stock' => (float) ($material?->current_stock ?? 0),
                'rate' => (float) ($material?->average_rate ?? 0),
                'minimum' => (float) ($material?->minimum_stock ?? 0),
                'unit' => (string) ($material?->unit ?? $item->unit),
            ];
        }

        if ($item->item_type === BomItemType::PackagingMaterial) {
            $material = $item->packagingMaterial ?? PackagingMaterial::query()->find($item->packaging_material_id);

            return [
                'name' => (string) ($material?->packaging_name ?? 'Unknown packaging material'),
                'stock' => (float) ($material?->current_stock ?? 0),
                'rate' => (float) ($material?->average_rate ?? 0),
                'minimum' => (float) ($material?->minimum_stock ?? 0),
                'unit' => (string) ($material?->unit ?? $item->unit),
            ];
        }

        $material = $item->semiFinished ?? SemiFinishedMaterial::query()->find($item->semi_finished_id);

        return [
            'name' => (string) ($material?->material_name ?? 'Unknown semi-finished material'),
            'stock' => (float) ($material?->current_stock ?? 0),
            'rate' => $material !== null
                ? $this->semiFinishedEstimatedRate((int) $material->id, $material)
                : 0.0,
            'minimum' => (float) ($material?->minimum_stock ?? 0),
            'unit' => (string) ($material?->unit ?? $item->unit),
        ];
    }

    /**
     * Prefer live WAVG from produced bulk; otherwise the manufacturing BOM formula cost per stock unit.
     */
    private function semiFinishedEstimatedRate(int $sfId, ?SemiFinishedMaterial $material): float
    {
        $wavg = (float) ($material?->average_production_cost ?? 0);
        if ($wavg > 0.00001) {
            return $wavg;
        }

        return $this->manufacturingFormulaCostPerInventoryUnit($sfId, $material);
    }

    private function manufacturingFormulaCostPerInventoryUnit(int $sfId, ?SemiFinishedMaterial $material): float
    {
        if (isset($this->computingSfFormulaRate[$sfId])) {
            return 0.0;
        }

        $this->computingSfFormulaRate[$sfId] = true;

        try {
            $mfgBom = $this->getActiveBomForSemiFinished($sfId);
            if ($mfgBom === null) {
                return 0.0;
            }

            $formulaQty = (float) $mfgBom->batch_quantity;
            if ($formulaQty <= 0) {
                return 0.0;
            }

            $summary = $this->summarizeBom($mfgBom, $mfgBom->items);
            $costPerFormulaUnit = (float) $summary['estimated_total_bom_cost'] / $formulaQty;
            $formulaUnit = (string) $mfgBom->batch_unit;
            $inventoryUnit = (string) ($material?->unit ?: $formulaUnit);

            if ($formulaUnit === '' || $inventoryUnit === '') {
                return round($costPerFormulaUnit, 4);
            }

            try {
                $converted = $this->unitConversion->convert(1, $inventoryUnit, $formulaUnit);

                return round($costPerFormulaUnit * (float) $converted['quantity'], 4);
            } catch (ValidationException) {
                return round($costPerFormulaUnit, 4);
            }
        } finally {
            unset($this->computingSfFormulaRate[$sfId]);
        }
    }
}
