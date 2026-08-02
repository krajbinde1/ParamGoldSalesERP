<?php

namespace App\Services\Inventory;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockTransactionType;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchConsumption;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductionService
{
    public function __construct(
        private readonly BOMCalculationService $bomCalculator = new BOMCalculationService,
        private readonly ProductionCostingService $costingService = new ProductionCostingService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly FinishedProductPostingService $finishedProductPosting = new FinishedProductPostingService,
        private readonly SemiFinishedPostingService $semiFinishedPosting = new SemiFinishedPostingService,
    ) {}

    /**
     * Preview production requirements and costing without posting stock.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        [$outputType, $product, $semiFinished, $bom] = $this->resolveOutputAndBom($input);

        $this->bomCalculator->assertActiveBomFormulaIsComplete($bom);

        $planned = (float) ($input['planned_quantity'] ?? 0);
        $actual = (float) ($input['actual_output_quantity'] ?? $planned);
        $this->validateQuantities($planned, $actual);

        $requirements = $this->bomCalculator->explodeRequirements($bom, $planned);
        $consumptionPreview = array_map(static function (array $row): array {
            return [
                'item_type' => $row['item_type'],
                'consumption_value' => $row['estimated_value'],
            ];
        }, $requirements);

        $costing = $this->costingService->calculate($consumptionPreview, $input, $actual);

        return [
            'output_type' => $outputType->value,
            'product' => $product,
            'semi_finished' => $semiFinished,
            'bom' => $bom,
            'requirements' => $requirements,
            'costing' => $costing,
            'has_mandatory_shortage' => collect($requirements)
                ->contains(fn (array $row): bool => ! $row['is_optional'] && $row['shortage_quantity'] > 0),
        ];
    }

    /**
     * Confirm and post a completed production batch inside a single transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function completeProduction(array $input, User $user): ProductionBatch
    {
        $postingToken = (string) ($input['posting_token'] ?? Str::uuid());

        $existing = ProductionBatch::query()->where('posting_token', $postingToken)->first();
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'production' => 'This production batch has already been posted.',
            ]);
        }

        return DB::transaction(function () use ($input, $user, $postingToken) {
            [$outputType, $product, $semiFinished, $bom] = $this->resolveOutputAndBom($input, lock: true);

            $this->bomCalculator->assertActiveBomFormulaIsComplete($bom);

            $planned = (float) $input['planned_quantity'];
            $actual = (float) ($input['actual_output_quantity'] ?? $planned);
            $wastage = max(0, (float) ($input['wastage_quantity'] ?? 0));
            $this->validateQuantities($planned, $actual);

            // Scale consumption from planned quantity (quantity-based BOM).
            $requirements = $this->bomCalculator->explodeRequirements($bom, $planned);
            $this->bomCalculator->assertMandatoryStockAvailable($requirements);

            $productionDate = $input['production_date'];
            $manufacturingDate = $input['manufacturing_date'] ?? $productionDate;
            $expiryDate = $input['expiry_date'] ?? null;

            if ($expiryDate === null && $product?->shelf_life_days) {
                $expiryDate = \Illuminate\Support\Carbon::parse($manufacturingDate)
                    ->addDays((int) $product->shelf_life_days)
                    ->toDateString();
            }

            $batchAttributes = [
                'output_type' => $outputType->value,
                'product_id' => $product?->id,
                'semi_finished_id' => $semiFinished?->id,
                'bom_id' => $bom->id,
                'bom_version' => $bom->bom_version,
                'production_date' => $productionDate,
                'manufacturing_date' => $manufacturingDate,
                'expiry_date' => $expiryDate,
                'planned_quantity' => $planned,
                'actual_output_quantity' => $actual,
                'wastage_quantity' => $wastage,
                'labour_cost' => (float) ($input['labour_cost'] ?? 0),
                'electricity_cost' => 0.0,
                'machine_cost' => 0.0,
                'processing_cost' => 0.0,
                'transport_cost' => (float) ($input['transport_cost'] ?? 0),
                'other_manufacturing_cost' => (float) ($input['other_manufacturing_cost'] ?? 0),
                'status' => ProductionBatchStatus::InProduction,
                'supervisor_id' => $user->id,
                'notes' => $input['notes'] ?? null,
                'posting_token' => $postingToken,
            ];

            if (filled($input['batch_number'] ?? null)) {
                $batchAttributes['batch_number'] = trim((string) $input['batch_number']);
            }

            $batch = ProductionBatch::query()->create($batchAttributes);

            $consumptionRows = [];

            foreach ($requirements as $row) {
                if ($row['is_optional'] && $row['shortage_quantity'] > 0) {
                    continue;
                }

                $consumedQty = (float) $row['required_quantity'];
                if ($consumedQty <= 0) {
                    continue;
                }

                $consumption = $this->postConsumptionLine($batch, $row, $productionDate, $user);
                $consumptionRows[] = [
                    'item_type' => $consumption->item_type->value,
                    'consumption_value' => (float) $consumption->consumption_value,
                ];
            }

            $costing = $this->costingService->calculate($consumptionRows, [
                'labour_cost' => $batch->labour_cost,
                'transport_cost' => $batch->transport_cost,
                'other_manufacturing_cost' => $batch->other_manufacturing_cost,
            ], $actual);

            if ($outputType === BomOutputType::SemiFinished) {
                $this->semiFinishedPosting->postFromProduction(
                    $batch,
                    $semiFinished,
                    $actual,
                    (float) $costing['total_batch_cost'],
                    (float) $costing['cost_per_unit'],
                    $productionDate,
                    $user,
                );
            } else {
                $this->finishedProductPosting->postFromProduction(
                    $batch,
                    $product,
                    $actual,
                    (float) $costing['total_batch_cost'],
                    (float) $costing['cost_per_unit'],
                    $productionDate,
                    $user,
                );
            }

            $batch->fill([
                ...$costing,
                'status' => ProductionBatchStatus::Completed,
                'completed_at' => now(),
            ]);
            $batch->save();

            return $batch->fresh(['product', 'semiFinished', 'bom', 'consumptions', 'supervisor']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: BomOutputType, 1: ?Product, 2: ?SemiFinishedMaterial, 3: Bom}
     */
    private function resolveOutputAndBom(array $input, bool $lock = false): array
    {
        $rawType = $input['output_type'] ?? BomOutputType::FinishedProduct->value;
        $outputType = $rawType instanceof BomOutputType
            ? $rawType
            : (BomOutputType::tryFrom((string) $rawType) ?? BomOutputType::FinishedProduct);

        if ($outputType === BomOutputType::SemiFinished) {
            $semiFinishedId = (int) ($input['semi_finished_id'] ?? 0);
            if ($semiFinishedId < 1) {
                throw ValidationException::withMessages([
                    'semi_finished_id' => 'Select a semi-finished material to produce.',
                ]);
            }

            $semiFinished = $lock
                ? $this->inventoryService->lockSemiFinishedMaterial($semiFinishedId)
                : SemiFinishedMaterial::query()->findOrFail($semiFinishedId);

            $bom = $lock
                ? Bom::query()
                    ->with(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished'])
                    ->where('output_type', BomOutputType::SemiFinished)
                    ->where('semi_finished_id', $semiFinished->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first()
                : $this->bomCalculator->getActiveBomForSemiFinished($semiFinished);

            if ($bom === null) {
                throw ValidationException::withMessages([
                    'semi_finished_id' => 'No active BOM found for the selected semi-finished material.',
                ]);
            }

            return [$outputType, null, $semiFinished, $bom];
        }

        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId < 1) {
            throw ValidationException::withMessages([
                'product_id' => 'Select a finished product to produce.',
            ]);
        }

        $product = $lock
            ? $this->inventoryService->lockProduct($productId)
            : Product::query()->findOrFail($productId);

        $bom = $lock
            ? Bom::query()
                ->with(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished'])
                ->where('output_type', BomOutputType::FinishedProduct)
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first()
            : $this->bomCalculator->getActiveBomForProduct($product);

        if ($bom === null) {
            throw ValidationException::withMessages([
                'product_id' => 'No active BOM found for the selected product.',
            ]);
        }

        return [$outputType, $product, null, $bom];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function postConsumptionLine(
        ProductionBatch $batch,
        array $row,
        string $productionDate,
        User $user,
    ): ProductionBatchConsumption {
        $itemType = (string) $row['item_type'];
        $consumedQty = (float) $row['required_quantity'];
        $inventoryUnit = (string) ($row['inventory_unit'] ?? $row['unit'] ?? '');

        if ($itemType === BomItemType::RawMaterial->value) {
            $material = $this->inventoryService->lockRawMaterial((int) $row['raw_material_id']);
            $rate = (float) $material->average_rate;
            $stockBefore = (float) $material->current_stock;
            $inventoryUnit = (string) ($row['inventory_unit'] ?? $material->unit);

            $this->ledgerService->postRawMaterialMovement(
                $material,
                0,
                $consumedQty,
                $rate,
                $this->consumptionMeta($batch, $productionDate, $row, $inventoryUnit),
                $user,
            );

            return ProductionBatchConsumption::query()->create([
                'production_batch_id' => $batch->id,
                'bom_item_id' => $row['bom_item_id'] ?? null,
                'item_type' => BomItemType::RawMaterial,
                'raw_material_id' => $material->id,
                'material_name' => $material->material_name,
                'unit' => $inventoryUnit,
                'inventory_unit' => $inventoryUnit,
                'formulation_quantity' => $row['formulation_quantity'] ?? $consumedQty,
                'formulation_unit' => $row['formulation_unit'] ?? $inventoryUnit,
                'required_quantity' => $consumedQty,
                'standard_quantity' => $consumedQty,
                'consumed_quantity' => $consumedQty,
                'stock_before' => $stockBefore,
                'stock_after' => (float) $material->fresh()->current_stock,
                'rate' => $rate,
                'consumption_value' => round($consumedQty * $rate, 2),
                'is_optional' => $row['is_optional'],
                'conversion_ratio' => $row['conversion_factor'] ?? 1,
            ]);
        }

        if ($itemType === BomItemType::PackagingMaterial->value) {
            $material = $this->inventoryService->lockPackagingMaterial((int) $row['packaging_material_id']);
            $rate = (float) $material->average_rate;
            $stockBefore = (float) $material->current_stock;
            $inventoryUnit = (string) ($row['inventory_unit'] ?? $material->unit);

            $this->ledgerService->postPackagingMaterialMovement(
                $material,
                0,
                $consumedQty,
                $rate,
                $this->consumptionMeta($batch, $productionDate, $row, $inventoryUnit),
                $user,
            );

            return ProductionBatchConsumption::query()->create([
                'production_batch_id' => $batch->id,
                'bom_item_id' => $row['bom_item_id'] ?? null,
                'item_type' => BomItemType::PackagingMaterial,
                'packaging_material_id' => $material->id,
                'material_name' => $material->packaging_name,
                'unit' => $inventoryUnit,
                'inventory_unit' => $inventoryUnit,
                'formulation_quantity' => $row['formulation_quantity'] ?? $consumedQty,
                'formulation_unit' => $row['formulation_unit'] ?? $inventoryUnit,
                'required_quantity' => $consumedQty,
                'standard_quantity' => $consumedQty,
                'consumed_quantity' => $consumedQty,
                'stock_before' => $stockBefore,
                'stock_after' => (float) $material->fresh()->current_stock,
                'rate' => $rate,
                'consumption_value' => round($consumedQty * $rate, 2),
                'is_optional' => $row['is_optional'],
                'conversion_ratio' => $row['conversion_factor'] ?? 1,
            ]);
        }

        $material = $this->inventoryService->lockSemiFinishedMaterial((int) $row['semi_finished_id']);
        $rate = (float) $material->average_production_cost;
        $stockBefore = (float) $material->current_stock;
        $inventoryUnit = (string) ($row['inventory_unit'] ?? $material->unit);

        $this->ledgerService->postSemiFinishedMovement(
            $material,
            0,
            $consumedQty,
            $rate,
            $this->consumptionMeta($batch, $productionDate, $row, $inventoryUnit),
            $user,
        );

        return ProductionBatchConsumption::query()->create([
            'production_batch_id' => $batch->id,
            'bom_item_id' => $row['bom_item_id'] ?? null,
            'item_type' => BomItemType::SemiFinished,
            'semi_finished_id' => $material->id,
            'material_name' => $material->material_name,
            'unit' => $inventoryUnit,
            'inventory_unit' => $inventoryUnit,
            'formulation_quantity' => $row['formulation_quantity'] ?? $consumedQty,
            'formulation_unit' => $row['formulation_unit'] ?? $inventoryUnit,
            'required_quantity' => $consumedQty,
            'standard_quantity' => $consumedQty,
            'consumed_quantity' => $consumedQty,
            'stock_before' => $stockBefore,
            'stock_after' => (float) $material->fresh()->current_stock,
            'rate' => $rate,
            'consumption_value' => round($consumedQty * $rate, 2),
            'is_optional' => $row['is_optional'],
            'conversion_ratio' => $row['conversion_factor'] ?? 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function consumptionMeta(
        ProductionBatch $batch,
        string $productionDate,
        array $row,
        string $inventoryUnit,
    ): array {
        return [
            'transaction_date' => $productionDate,
            'transaction_type' => StockTransactionType::ProductionConsumption,
            'reference_type' => ProductionBatch::class,
            'reference_id' => $batch->id,
            'reference_number' => $batch->batch_number,
            'batch_number' => $batch->batch_number,
            'remarks' => sprintf(
                'Production consumption (%s %s)',
                $row['formulation_quantity'] ?? $row['required_quantity'] ?? 0,
                $row['formulation_unit'] ?? $inventoryUnit,
            ),
        ];
    }

    private function validateQuantities(float $planned, float $actual): void
    {
        if ($planned <= 0) {
            throw ValidationException::withMessages([
                'planned_quantity' => 'Planned quantity must be greater than zero.',
            ]);
        }

        if ($actual <= 0) {
            throw ValidationException::withMessages([
                'actual_output_quantity' => 'Actual output quantity must be greater than zero.',
            ]);
        }

        $tolerance = (float) config('inventory.output_tolerance_percent', 20);
        $maxAllowed = round($planned * (1 + ($tolerance / 100)), 3);

        if ($actual > $maxAllowed) {
            throw ValidationException::withMessages([
                'actual_output_quantity' => "Actual output exceeds allowed tolerance of {$tolerance}% over planned quantity (max {$maxAllowed}).",
            ]);
        }
    }
}
