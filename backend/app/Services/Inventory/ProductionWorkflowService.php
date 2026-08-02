<?php

namespace App\Services\Inventory;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\MaterialSubstitutionReason;
use App\Enums\ProductionBatchStatus;
use App\Enums\StockTransactionType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\BomItemAlternate;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchConsumption;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductionWorkflowService
{
    public function __construct(
        private readonly BOMCalculationService $bomCalculator = new BOMCalculationService,
        private readonly ProductionCostingService $costingService = new ProductionCostingService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly FinishedProductPostingService $finishedProductPosting = new FinishedProductPostingService,
        private readonly SemiFinishedPostingService $semiFinishedPosting = new SemiFinishedPostingService,
        private readonly ProductionService $productionService = new ProductionService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input, User $user): array
    {
        $outputType = $this->resolveOutputType($input);

        // Semi-finished uses the simplified ProductionService path (no substitutions).
        if ($outputType === BomOutputType::SemiFinished) {
            $simple = $this->productionService->preview($input);

            return [
                'output_type' => BomOutputType::SemiFinished->value,
                'product' => null,
                'semi_finished' => $simple['semi_finished'],
                'bom' => $simple['bom'],
                'requirements' => $simple['requirements'],
                'costing' => $simple['costing'],
                'flags' => [
                    'has_material_deviation' => false,
                    'has_quantity_variance' => false,
                    'requires_approval' => false,
                ],
                'can_view_costs' => $user->canViewProductionCosts(),
                'has_mandatory_shortage' => $simple['has_mandatory_shortage'],
                'requires_approval' => false,
                'substitution_reasons' => MaterialSubstitutionReason::options(),
            ];
        }

        $product = Product::query()->findOrFail((int) $input['product_id']);
        $bom = $this->bomCalculator->getActiveBomForProduct($product);

        if ($bom === null) {
            throw ValidationException::withMessages([
                'product_id' => 'No active BOM found for the selected product.',
            ]);
        }

        $this->bomCalculator->assertActiveBomFormulaIsComplete($bom);

        $bom->load(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished', 'items.approvedAlternates.rawMaterial', 'items.approvedAlternates.packagingMaterial']);

        $planned = (float) ($input['planned_quantity'] ?? 0);
        $actual = (float) ($input['actual_output_quantity'] ?? $planned);
        $this->validateQuantities($planned, $actual);

        $baseRequirements = $this->bomCalculator->explodeRequirements($bom, $planned);
        $lines = $this->resolveConsumptionLines($baseRequirements, $input['materials'] ?? [], $bom);
        $flags = $this->evaluateDeviationFlags($lines);
        $costing = $this->costingService->calculate(
            array_map(static fn (array $row): array => [
                'item_type' => $row['item_type'],
                'consumption_value' => $row['estimated_value'],
            ], $lines),
            $input,
            $actual,
        );

        return [
            'output_type' => BomOutputType::FinishedProduct->value,
            'product' => $product,
            'semi_finished' => null,
            'bom' => $bom,
            'requirements' => $lines,
            'costing' => $costing,
            'flags' => $flags,
            'can_view_costs' => $user->canViewProductionCosts(),
            'has_mandatory_shortage' => collect($lines)
                ->contains(fn (array $row): bool => ! $row['is_optional'] && $row['shortage_quantity'] > 0),
            'requires_approval' => $flags['requires_approval'],
            'substitution_reasons' => MaterialSubstitutionReason::options(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveOutputType(array $input): BomOutputType
    {
        $raw = $input['output_type'] ?? BomOutputType::FinishedProduct->value;

        return $raw instanceof BomOutputType
            ? $raw
            : (BomOutputType::tryFrom((string) $raw) ?? BomOutputType::FinishedProduct);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveDraft(array $input, User $user, ?ProductionBatch $existing = null): ProductionBatch
    {
        if ($existing !== null && ! $existing->status->isEditableDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or rejected batches can be edited.',
            ]);
        }

        return DB::transaction(function () use ($input, $user, $existing) {
            $preview = $this->preview($input, $user);
            /** @var Bom $bom */
            $bom = $preview['bom'];
            /** @var Product $product */
            $product = $preview['product'];

            $payload = [
                'product_id' => $product->id,
                'bom_id' => $bom->id,
                'bom_version' => $bom->bom_version,
                'production_date' => $input['production_date'],
                'manufacturing_date' => $input['manufacturing_date'] ?? $input['production_date'],
                'expiry_date' => $input['expiry_date'] ?? null,
                'planned_quantity' => (float) $input['planned_quantity'],
                'actual_output_quantity' => (float) ($input['actual_output_quantity'] ?? $input['planned_quantity']),
                // Wastage is no longer captured on the simplified production
                // entry form; it is always persisted as zero.
                'wastage_quantity' => 0.0,
                'labour_cost' => (float) ($input['labour_cost'] ?? 0),
                // Electricity/Machine/Processing costs have been removed from
                // the manufacturing expenses UI and formula; always persist 0.
                'electricity_cost' => 0.0,
                'machine_cost' => 0.0,
                'processing_cost' => 0.0,
                'transport_cost' => (float) ($input['transport_cost'] ?? 0),
                'other_manufacturing_cost' => (float) ($input['other_manufacturing_cost'] ?? 0),
                'notes' => $input['notes'] ?? null,
                'supervisor_id' => $user->id,
                'status' => ProductionBatchStatus::Draft,
                'has_material_deviation' => $preview['flags']['has_material_deviation'],
                'has_quantity_variance' => $preview['flags']['has_quantity_variance'],
                'requires_approval' => $preview['flags']['requires_approval'],
                ...$preview['costing'],
            ];

            if ($payload['expiry_date'] === null && $product->shelf_life_days) {
                $payload['expiry_date'] = \Illuminate\Support\Carbon::parse($payload['manufacturing_date'])
                    ->addDays((int) $product->shelf_life_days)
                    ->toDateString();
            }

            $batch = $existing ?? new ProductionBatch;
            $batch->fill($payload);
            $batch->save();

            $batch->consumptions()->delete();
            foreach ($preview['requirements'] as $line) {
                $this->storeConsumptionLine($batch, $line);
            }

            return $batch->fresh(['product', 'bom', 'consumptions', 'supervisor']);
        });
    }

    public function submitForApproval(ProductionBatch $batch, User $user): ProductionBatch
    {
        if (! $batch->status->isEditableDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or rejected batches can be submitted for approval.',
            ]);
        }

        if (! $batch->requires_approval && ! $batch->has_material_deviation && ! $batch->has_quantity_variance) {
            throw ValidationException::withMessages([
                'approval' => 'This batch does not require deviation approval. Start or complete production instead.',
            ]);
        }

        $batch->status = ProductionBatchStatus::DeviationPendingApproval;
        $batch->submitted_for_approval_at = now();
        $batch->rejected_by = null;
        $batch->rejected_at = null;
        $batch->rejection_reason = null;
        $batch->save();

        return $batch->fresh(['product', 'bom', 'consumptions', 'supervisor']);
    }

    public function approveDeviation(ProductionBatch $batch, User $director, ?string $notes = null): ProductionBatch
    {
        if (! $director->isDirectorUser() && ! $director->isAdminUser()) {
            throw ValidationException::withMessages([
                'authorization' => 'Only Director or Admin can approve production deviations.',
            ]);
        }

        if ($batch->supervisor_id === $director->id) {
            throw ValidationException::withMessages([
                'authorization' => 'You cannot approve your own production deviation.',
            ]);
        }

        if ($batch->status !== ProductionBatchStatus::DeviationPendingApproval) {
            throw ValidationException::withMessages([
                'status' => 'Only pending deviation batches can be approved.',
            ]);
        }

        $batch->status = ProductionBatchStatus::Approved;
        $batch->approved_by = $director->id;
        $batch->approved_at = now();
        $batch->approval_notes = $notes;
        $batch->save();

        return $batch->fresh(['product', 'consumptions', 'approvedBy']);
    }

    public function rejectDeviation(ProductionBatch $batch, User $director, string $reason): ProductionBatch
    {
        if (! $director->isDirectorUser() && ! $director->isAdminUser()) {
            throw ValidationException::withMessages([
                'authorization' => 'Only Director or Admin can reject production deviations.',
            ]);
        }

        if ($batch->status !== ProductionBatchStatus::DeviationPendingApproval) {
            throw ValidationException::withMessages([
                'status' => 'Only pending deviation batches can be rejected.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Rejection reason is required.',
            ]);
        }

        $batch->status = ProductionBatchStatus::Rejected;
        $batch->rejected_by = $director->id;
        $batch->rejected_at = now();
        $batch->rejection_reason = $reason;
        $batch->save();

        return $batch->fresh(['product', 'consumptions', 'rejectedBy']);
    }

    public function startProduction(ProductionBatch $batch, User $user): ProductionBatch
    {
        $allowed = [
            ProductionBatchStatus::Draft,
            ProductionBatchStatus::MaterialChecked,
            ProductionBatchStatus::Approved,
        ];

        if (! in_array($batch->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Batch cannot be started from the current status.',
            ]);
        }

        if ($batch->requires_approval && $batch->status !== ProductionBatchStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'Deviation approval is required before starting production.',
            ]);
        }

        $batch->status = ProductionBatchStatus::InProduction;
        $batch->started_at = now();
        $batch->save();

        return $batch->fresh(['product', 'consumptions']);
    }

    public function cancelDraft(ProductionBatch $batch, User $user): ProductionBatch
    {
        if (! $batch->status->isEditableDraft() && $batch->status !== ProductionBatchStatus::DeviationPendingApproval) {
            throw ValidationException::withMessages([
                'status' => 'Only draft, rejected, or pending batches can be cancelled.',
            ]);
        }

        $batch->status = ProductionBatchStatus::Cancelled;
        $batch->save();

        return $batch->fresh();
    }

    /**
     * Complete production from a saved batch or direct payload. Posts stock.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(ProductionBatch|array $batchOrInput, User $user, ?string $postingToken = null): ProductionBatch
    {
        if (is_array($batchOrInput) && $this->resolveOutputType($batchOrInput) === BomOutputType::SemiFinished) {
            $batchOrInput['posting_token'] = $postingToken ?: ($batchOrInput['posting_token'] ?? (string) Str::uuid());

            return $this->productionService->completeProduction($batchOrInput, $user);
        }

        $token = $postingToken ?: (string) (is_array($batchOrInput) ? ($batchOrInput['posting_token'] ?? Str::uuid()) : ($batchOrInput->posting_token ?: Str::uuid()));

        $existingPosted = ProductionBatch::query()
            ->where('posting_token', $token)
            ->where('status', ProductionBatchStatus::Completed)
            ->first();
        if ($existingPosted !== null) {
            throw ValidationException::withMessages([
                'production' => 'This production batch has already been posted.',
            ]);
        }

        return DB::transaction(function () use ($batchOrInput, $user, $token) {
            if ($batchOrInput instanceof ProductionBatch) {
                /** @var ProductionBatch $batch */
                $batch = ProductionBatch::query()->whereKey($batchOrInput->id)->lockForUpdate()->firstOrFail();
                $this->finishedProductPosting->assertNotPosted($batch);
                $this->assertCompletable($batch);

                $input = [
                    'product_id' => $batch->product_id,
                    'planned_quantity' => (float) $batch->planned_quantity,
                    'actual_output_quantity' => (float) $batch->actual_output_quantity,
                    'wastage_quantity' => 0.0,
                    'production_date' => $batch->production_date?->toDateString(),
                    'manufacturing_date' => $batch->manufacturing_date?->toDateString(),
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'labour_cost' => (float) $batch->labour_cost,
                    'electricity_cost' => 0.0,
                    'machine_cost' => 0.0,
                    'processing_cost' => 0.0,
                    'transport_cost' => (float) $batch->transport_cost,
                    'other_manufacturing_cost' => (float) $batch->other_manufacturing_cost,
                    'notes' => $batch->notes,
                    'materials' => $batch->consumptions->map(fn (ProductionBatchConsumption $c): array => [
                        'bom_item_id' => $c->bom_item_id,
                        'item_type' => $c->item_type->value,
                        'raw_material_id' => $c->raw_material_id,
                        'packaging_material_id' => $c->packaging_material_id,
                        'consumed_quantity' => (float) $c->consumed_quantity,
                        'is_substituted' => $c->is_substituted,
                        'substitution_reason' => $c->substitution_reason,
                        'substitution_remarks' => $c->substitution_remarks,
                        'conversion_ratio' => (float) $c->conversion_ratio,
                        'is_optional' => $c->is_optional,
                    ])->all(),
                ];
            } else {
                $batch = $this->saveDraft($batchOrInput, $user);
                $batch = ProductionBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $input = $batchOrInput;
            }

            $preview = $this->preview($input, $user);

            if ($preview['has_mandatory_shortage']) {
                throw ValidationException::withMessages([
                    'stock' => 'Insufficient mandatory material stock. Revalidate stock before completing production.',
                ]);
            }

            if ($preview['requires_approval'] && $batch->status !== ProductionBatchStatus::Approved && $batch->status !== ProductionBatchStatus::InProduction) {
                // If still draft with deviations, force approval path
                if ($batch->status->isEditableDraft()) {
                    $batch->fill([
                        'has_material_deviation' => $preview['flags']['has_material_deviation'],
                        'has_quantity_variance' => $preview['flags']['has_quantity_variance'],
                        'requires_approval' => true,
                    ]);
                    $batch->save();

                    throw ValidationException::withMessages([
                        'approval' => 'Material substitution or quantity variance requires Director approval before stock posting.',
                    ]);
                }
            }

            $product = $this->inventoryService->lockProduct((int) $batch->product_id);
            $productionDate = $batch->production_date?->toDateString() ?? now('Asia/Kolkata')->toDateString();
            $actual = (float) $batch->actual_output_quantity;

            $batch->consumptions()->delete();
            $consumptionRows = [];

            foreach ($preview['requirements'] as $line) {
                if ($line['is_optional'] && $line['shortage_quantity'] > 0) {
                    continue;
                }

                $consumedQty = (float) $line['consumed_quantity'];
                if ($consumedQty <= 0) {
                    continue;
                }

                if ($line['item_type'] === BomItemType::RawMaterial->value) {
                    $material = $this->inventoryService->lockRawMaterial((int) $line['raw_material_id']);
                    $rate = (float) $material->average_rate;
                    $stockBefore = (float) $material->current_stock;

                    $this->ledgerService->postRawMaterialMovement(
                        $material,
                        0,
                        $consumedQty,
                        $rate,
                        [
                            'transaction_date' => $productionDate,
                            'transaction_type' => StockTransactionType::ProductionConsumption,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $batch->id,
                            'reference_number' => $batch->batch_number,
                            'batch_number' => $batch->batch_number,
                            'remarks' => $line['is_substituted'] ? 'Production consumption (substituted)' : 'Production consumption',
                        ],
                        $user,
                    );

                    $line['stock_before'] = $stockBefore;
                    $line['stock_after'] = (float) $material->fresh()->current_stock;
                    $line['rate'] = $rate;
                    $line['estimated_value'] = round($consumedQty * $rate, 2);
                } elseif ($line['item_type'] === BomItemType::PackagingMaterial->value) {
                    $material = $this->inventoryService->lockPackagingMaterial((int) $line['packaging_material_id']);
                    $rate = (float) $material->average_rate;
                    $stockBefore = (float) $material->current_stock;

                    $this->ledgerService->postPackagingMaterialMovement(
                        $material,
                        0,
                        $consumedQty,
                        $rate,
                        [
                            'transaction_date' => $productionDate,
                            'transaction_type' => StockTransactionType::ProductionConsumption,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $batch->id,
                            'reference_number' => $batch->batch_number,
                            'batch_number' => $batch->batch_number,
                            'remarks' => $line['is_substituted'] ? 'Production consumption (substituted)' : 'Production consumption',
                        ],
                        $user,
                    );

                    $line['stock_before'] = $stockBefore;
                    $line['stock_after'] = (float) $material->fresh()->current_stock;
                    $line['rate'] = $rate;
                    $line['estimated_value'] = round($consumedQty * $rate, 2);
                } elseif ($line['item_type'] === BomItemType::SemiFinished->value) {
                    $material = $this->inventoryService->lockSemiFinishedMaterial((int) $line['semi_finished_id']);
                    $rate = (float) $material->average_production_cost;
                    $stockBefore = (float) $material->current_stock;

                    $this->ledgerService->postSemiFinishedMovement(
                        $material,
                        0,
                        $consumedQty,
                        $rate,
                        [
                            'transaction_date' => $productionDate,
                            'transaction_type' => StockTransactionType::ProductionConsumption,
                            'reference_type' => ProductionBatch::class,
                            'reference_id' => $batch->id,
                            'reference_number' => $batch->batch_number,
                            'batch_number' => $batch->batch_number,
                            'remarks' => ($line['is_substituted'] ?? false) ? 'Production consumption (substituted)' : 'Production consumption',
                        ],
                        $user,
                    );

                    $line['stock_before'] = $stockBefore;
                    $line['stock_after'] = (float) $material->fresh()->current_stock;
                    $line['rate'] = $rate;
                    $line['estimated_value'] = round($consumedQty * $rate, 2);
                }

                $this->storeConsumptionLine($batch, $line, posted: true);
                $consumptionRows[] = [
                    'item_type' => $line['item_type'],
                    'consumption_value' => $line['estimated_value'],
                ];
            }

            $costing = $this->costingService->calculate($consumptionRows, [
                'labour_cost' => $batch->labour_cost,
                'transport_cost' => $batch->transport_cost,
                'other_manufacturing_cost' => $batch->other_manufacturing_cost,
            ], $actual);

            $this->finishedProductPosting->postFromProduction(
                $batch,
                $product,
                $actual,
                (float) $costing['total_batch_cost'],
                (float) $costing['cost_per_unit'],
                $productionDate,
                $user,
            );

            $batch->fill([
                ...$costing,
                'status' => ProductionBatchStatus::Completed,
                'completed_at' => now(),
                'posting_token' => $token,
                'has_material_deviation' => $preview['flags']['has_material_deviation'],
                'has_quantity_variance' => $preview['flags']['has_quantity_variance'],
            ]);
            $batch->save();

            return $batch->fresh(['product', 'bom', 'consumptions', 'supervisor']);
        });
    }

    private function assertCompletable(ProductionBatch $batch): void
    {
        $allowed = [
            ProductionBatchStatus::Draft,
            ProductionBatchStatus::MaterialChecked,
            ProductionBatchStatus::Approved,
            ProductionBatchStatus::InProduction,
        ];

        if (! in_array($batch->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Batch cannot be completed from status '.$batch->status->label().'.',
            ]);
        }

        if ($batch->requires_approval && ! in_array($batch->status, [ProductionBatchStatus::Approved, ProductionBatchStatus::InProduction], true)) {
            throw ValidationException::withMessages([
                'approval' => 'Director approval is required before completing this production batch.',
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $baseRequirements
     * @param  list<array<string, mixed>>  $materialOverrides
     * @return list<array<string, mixed>>
     */
    private function resolveConsumptionLines(array $baseRequirements, array $materialOverrides, Bom $bom): array
    {
        $overridesByBomItem = collect($materialOverrides)->keyBy(fn (array $row) => (string) ($row['bom_item_id'] ?? ''));
        $lines = [];

        foreach ($baseRequirements as $base) {
            $bomItemId = (int) $base['bom_item_id'];
            $override = $overridesByBomItem->get((string) $bomItemId, []);
            /** @var BomItem|null $bomItem */
            $bomItem = $bom->items->firstWhere('id', $bomItemId);

            $standardQty = (float) $base['required_quantity'];
            $conversionRatio = (float) ($override['conversion_ratio'] ?? 1);
            if ($conversionRatio <= 0) {
                $conversionRatio = 1;
            }

            $isSubstituted = (bool) ($override['is_substituted'] ?? false);
            $rawId = $base['raw_material_id'];
            $packId = $base['packaging_material_id'];
            $sfId = $base['semi_finished_id'] ?? null;
            $materialName = $base['material_name'];
            $unit = $base['unit'];
            $rate = (float) $base['average_rate'];
            $available = (float) $base['available_stock'];
            $minimum = (float) $base['minimum_stock'];
            $originalName = $base['material_name'];

            if ($isSubstituted && $base['item_type'] === BomItemType::SemiFinished->value) {
                throw ValidationException::withMessages([
                    'materials' => "Semi-finished materials cannot be substituted ({$originalName}).",
                ]);
            }

            if ($isSubstituted) {
                $matchedAlternate = $this->assertApprovedAlternate($bomItem, $override);
                $conversionRatio = (float) ($override['conversion_ratio'] ?? $matchedAlternate->conversion_ratio);
                if ($conversionRatio <= 0) {
                    $conversionRatio = 1;
                }

                if ($base['item_type'] === BomItemType::RawMaterial->value) {
                    $rawId = (int) ($override['raw_material_id'] ?? 0);
                    $material = RawMaterial::query()->findOrFail($rawId);
                    $materialName = $material->material_name;
                    $unit = $material->unit;
                    $rate = (float) $material->average_rate;
                    $available = (float) $material->current_stock;
                    $minimum = (float) $material->minimum_stock;
                    $packId = null;
                } else {
                    $packId = (int) ($override['packaging_material_id'] ?? 0);
                    $material = PackagingMaterial::query()->findOrFail($packId);
                    $materialName = $material->packaging_name;
                    $unit = $material->unit;
                    $rate = (float) $material->average_rate;
                    $available = (float) $material->current_stock;
                    $minimum = (float) $material->minimum_stock;
                    $rawId = null;
                }

                $reason = MaterialSubstitutionReason::tryFrom((string) ($override['substitution_reason'] ?? ''));
                if ($reason === null) {
                    throw ValidationException::withMessages([
                        'materials' => "Substitution reason is required for {$originalName}.",
                    ]);
                }
                if ($reason->requiresRemarks() && blank($override['substitution_remarks'] ?? null)) {
                    throw ValidationException::withMessages([
                        'materials' => "Remarks are required when substitution reason is Other ({$originalName}).",
                    ]);
                }
            }

            $consumed = array_key_exists('consumed_quantity', $override)
                ? (float) $override['consumed_quantity']
                : round($standardQty * $conversionRatio, 4);

            if ($consumed < 0) {
                throw ValidationException::withMessages([
                    'materials' => "Consumed quantity cannot be negative for {$materialName}.",
                ]);
            }

            $varianceQty = round($consumed - ($standardQty * $conversionRatio), 4);
            $expected = max($standardQty * $conversionRatio, 0.0001);
            $variancePct = round((($consumed - $expected) / $expected) * 100, 3);
            $shortage = max(0, round($consumed - $available, 4));

            $stockStatus = 'available';
            if ($shortage > 0 && ! ($base['is_optional'] ?? false)) {
                $stockStatus = 'shortage';
            } elseif ($available <= 0) {
                $stockStatus = 'out_of_stock';
            } elseif ($available <= $minimum) {
                $stockStatus = 'low_stock';
            }

            $lines[] = [
                'bom_item_id' => $bomItemId,
                'item_type' => $base['item_type'],
                'raw_material_id' => $rawId,
                'packaging_material_id' => $packId,
                'semi_finished_id' => $sfId,
                'original_raw_material_id' => $base['raw_material_id'],
                'original_packaging_material_id' => $base['packaging_material_id'],
                'material_name' => $materialName,
                'original_material_name' => $originalName,
                'unit' => $unit,
                'inventory_unit' => $base['inventory_unit'] ?? $unit,
                'formulation_quantity' => $isSubstituted
                    ? round(((float) ($base['formulation_quantity'] ?? $standardQty)) * $conversionRatio, 6)
                    : ($base['formulation_quantity'] ?? $standardQty),
                'formulation_unit' => $base['formulation_unit'] ?? $unit,
                'standard_quantity' => $standardQty,
                'required_quantity' => round($standardQty * $conversionRatio, 4),
                'consumed_quantity' => $consumed,
                'variance_quantity' => $varianceQty,
                'variance_percentage' => $variancePct,
                'conversion_ratio' => $conversionRatio,
                'conversion_factor' => $base['conversion_factor'] ?? 1,
                'available_stock' => $available,
                'balance_after' => round($available - $consumed, 6),
                'shortage_quantity' => $shortage,
                'average_rate' => $rate,
                'estimated_value' => round($consumed * $rate, 2),
                'stock_status' => $stockStatus,
                'is_optional' => (bool) $base['is_optional'],
                'is_substituted' => $isSubstituted,
                'substitution_reason' => $isSubstituted ? ($override['substitution_reason'] ?? null) : null,
                'substitution_remarks' => $isSubstituted ? ($override['substitution_remarks'] ?? null) : null,
                'minimum_stock' => $minimum,
                'alternates' => $bomItem?->approvedAlternates->map(function (BomItemAlternate $alt): array {
                    $mat = $alt->rawMaterial ?? $alt->packagingMaterial;

                    return [
                        'id' => $alt->id,
                        'item_type' => $alt->item_type,
                        'raw_material_id' => $alt->raw_material_id,
                        'packaging_material_id' => $alt->packaging_material_id,
                        'material_name' => $alt->materialName(),
                        'unit' => $mat?->unit,
                        'current_stock' => (float) ($mat?->current_stock ?? 0),
                        'average_rate' => (float) ($mat?->average_rate ?? 0),
                        'conversion_ratio' => (float) $alt->conversion_ratio,
                        'priority' => $alt->priority,
                        'remarks' => $alt->remarks,
                    ];
                })->values()->all() ?? [],
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function assertApprovedAlternate(?BomItem $bomItem, array $override): BomItemAlternate
    {
        if ($bomItem === null) {
            throw ValidationException::withMessages([
                'materials' => 'Invalid BOM item for material substitution.',
            ]);
        }

        $approved = $bomItem->approvedAlternates;
        $match = $approved->first(function (BomItemAlternate $alt) use ($override, $bomItem): bool {
            if ($bomItem->item_type === BomItemType::RawMaterial) {
                return (int) $alt->raw_material_id === (int) ($override['raw_material_id'] ?? 0);
            }

            return (int) $alt->packaging_material_id === (int) ($override['packaging_material_id'] ?? 0);
        });

        if ($match === null) {
            throw ValidationException::withMessages([
                'materials' => 'Selected alternate material is not an approved substitute for this BOM item.',
            ]);
        }

        return $match;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{has_material_deviation: bool, has_quantity_variance: bool, requires_approval: bool}
     */
    private function evaluateDeviationFlags(array $lines): array
    {
        $tolerance = (float) config('inventory.quantity_variance_tolerance_percent', 10);
        $hasSub = false;
        $hasVar = false;

        foreach ($lines as $line) {
            if ($line['is_substituted']) {
                $hasSub = true;
            }
            if (abs((float) $line['variance_percentage']) > $tolerance) {
                $hasVar = true;
            }
        }

        return [
            'has_material_deviation' => $hasSub,
            'has_quantity_variance' => $hasVar,
            'requires_approval' => $hasSub || $hasVar,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function storeConsumptionLine(ProductionBatch $batch, array $line, bool $posted = false): void
    {
        ProductionBatchConsumption::query()->create([
            'production_batch_id' => $batch->id,
            'bom_item_id' => $line['bom_item_id'] ?? null,
            'item_type' => $line['item_type'],
            'raw_material_id' => $line['raw_material_id'] ?? null,
            'packaging_material_id' => $line['packaging_material_id'] ?? null,
            'semi_finished_id' => $line['semi_finished_id'] ?? null,
            'original_raw_material_id' => $line['original_raw_material_id'] ?? null,
            'original_packaging_material_id' => $line['original_packaging_material_id'] ?? null,
            'material_name' => $line['material_name'],
            'original_material_name' => $line['original_material_name'] ?? $line['material_name'],
            'unit' => $line['unit'],
            'inventory_unit' => $line['inventory_unit'] ?? $line['unit'],
            'formulation_quantity' => $line['formulation_quantity'] ?? $line['required_quantity'],
            'formulation_unit' => $line['formulation_unit'] ?? $line['unit'],
            'required_quantity' => $line['required_quantity'],
            'standard_quantity' => $line['standard_quantity'],
            'consumed_quantity' => $line['consumed_quantity'],
            'variance_quantity' => $line['variance_quantity'],
            'variance_percentage' => $line['variance_percentage'],
            'conversion_ratio' => $line['conversion_ratio'] ?? 1,
            'stock_before' => $posted ? ($line['stock_before'] ?? 0) : ($line['available_stock'] ?? 0),
            'stock_after' => $posted ? ($line['stock_after'] ?? 0) : ($line['available_stock'] ?? 0),
            'rate' => $line['rate'] ?? $line['average_rate'] ?? 0,
            'consumption_value' => $line['estimated_value'] ?? 0,
            'is_optional' => $line['is_optional'] ?? false,
            'is_substituted' => $line['is_substituted'] ?? false,
            'substitution_reason' => $line['substitution_reason'] ?? null,
            'substitution_remarks' => $line['substitution_remarks'] ?? null,
        ]);
    }

    private function validateQuantities(float $planned, float $actual): void
    {
        if ($planned <= 0) {
            throw ValidationException::withMessages([
                'planned_quantity' => 'Planned production quantity must be greater than zero.',
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
