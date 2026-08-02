<?php

namespace App\Services\Inventory;

use App\Enums\StockAdjustmentType;
use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    public function __construct(
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
    ) {}

    public function lockRawMaterial(int $id): RawMaterial
    {
        /** @var RawMaterial $material */
        $material = RawMaterial::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $material;
    }

    public function lockPackagingMaterial(int $id): PackagingMaterial
    {
        /** @var PackagingMaterial $material */
        $material = PackagingMaterial::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $material;
    }

    public function lockProduct(int $id): Product
    {
        /** @var Product $product */
        $product = Product::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $product;
    }

    public function lockSemiFinishedMaterial(int $id): SemiFinishedMaterial
    {
        /** @var SemiFinishedMaterial $material */
        $material = SemiFinishedMaterial::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $material;
    }

    public function refreshStockValue(RawMaterial|PackagingMaterial|SemiFinishedMaterial $material): void
    {
        if ($material instanceof SemiFinishedMaterial) {
            $material->current_stock_value = round(
                (float) $material->current_stock * (float) $material->average_production_cost,
                2,
            );
        } else {
            $material->current_stock_value = round(
                (float) $material->current_stock * (float) $material->average_rate,
                2,
            );
        }
        $material->save();
    }

    public function adjustStock(array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($data, $user) {
            $itemType = StockItemType::from($data['item_type']);
            $adjustmentType = StockAdjustmentType::from($data['adjustment_type']);
            $adjustedQty = round((float) $data['adjusted_quantity'], 3);

            if ($adjustedQty <= 0 && $adjustmentType !== StockAdjustmentType::PhysicalStockCorrection) {
                throw ValidationException::withMessages([
                    'adjusted_quantity' => 'Adjusted quantity must be greater than zero.',
                ]);
            }

            if ($itemType === StockItemType::RawMaterial) {
                $material = $this->lockRawMaterial((int) $data['raw_material_id']);
                $systemStock = (float) $material->current_stock;
                [$qtyIn, $qtyOut] = $this->resolveAdjustmentDelta(
                    $adjustmentType,
                    $systemStock,
                    $adjustedQty,
                    (float) ($data['physical_stock'] ?? 0),
                );
                $rate = (float) $material->average_rate;

                $ledger = $this->ledgerService->postRawMaterialMovement(
                    $material,
                    $qtyIn,
                    $qtyOut,
                    $rate,
                    [
                        'transaction_date' => $data['adjustment_date'],
                        'transaction_type' => $this->mapAdjustmentTransactionType($adjustmentType),
                        'remarks' => $data['remarks'] ?? $data['reason'] ?? null,
                    ],
                    $user,
                );

                $adjustment = $this->createAdjustmentRecord($data, $adjustmentType, $itemType, [
                    'raw_material_id' => $material->id,
                    'system_stock' => $systemStock,
                    'stock_after' => (float) $material->fresh()->current_stock,
                    'rate' => $rate,
                    'adjusted_quantity' => max($qtyIn, $qtyOut),
                ], $user);

                $ledger->update([
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'reference_number' => $adjustment->adjustment_number,
                ]);

                return $adjustment;
            }

            if ($itemType === StockItemType::PackagingMaterial) {
                $material = $this->lockPackagingMaterial((int) $data['packaging_material_id']);
                $systemStock = (float) $material->current_stock;
                [$qtyIn, $qtyOut] = $this->resolveAdjustmentDelta(
                    $adjustmentType,
                    $systemStock,
                    $adjustedQty,
                    (float) ($data['physical_stock'] ?? 0),
                );
                $rate = (float) $material->average_rate;

                $ledger = $this->ledgerService->postPackagingMaterialMovement(
                    $material,
                    $qtyIn,
                    $qtyOut,
                    $rate,
                    [
                        'transaction_date' => $data['adjustment_date'],
                        'transaction_type' => $this->mapAdjustmentTransactionType($adjustmentType),
                        'remarks' => $data['remarks'] ?? $data['reason'] ?? null,
                    ],
                    $user,
                );

                $adjustment = $this->createAdjustmentRecord($data, $adjustmentType, $itemType, [
                    'packaging_material_id' => $material->id,
                    'system_stock' => $systemStock,
                    'stock_after' => (float) $material->fresh()->current_stock,
                    'rate' => $rate,
                    'adjusted_quantity' => max($qtyIn, $qtyOut),
                ], $user);

                $ledger->update([
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'reference_number' => $adjustment->adjustment_number,
                ]);

                return $adjustment;
            }

            if ($itemType === StockItemType::SemiFinished) {
                $material = $this->lockSemiFinishedMaterial((int) $data['semi_finished_id']);
                $systemStock = (float) $material->current_stock;
                [$qtyIn, $qtyOut] = $this->resolveAdjustmentDelta(
                    $adjustmentType,
                    $systemStock,
                    $adjustedQty,
                    (float) ($data['physical_stock'] ?? 0),
                );
                $rate = (float) $material->average_production_cost;

                $ledger = $this->ledgerService->postSemiFinishedMovement(
                    $material,
                    $qtyIn,
                    $qtyOut,
                    $rate,
                    [
                        'transaction_date' => $data['adjustment_date'],
                        'transaction_type' => $this->mapAdjustmentTransactionType($adjustmentType),
                        'remarks' => $data['remarks'] ?? $data['reason'] ?? null,
                    ],
                    $user,
                );

                $adjustment = $this->createAdjustmentRecord($data, $adjustmentType, $itemType, [
                    'semi_finished_id' => $material->id,
                    'system_stock' => $systemStock,
                    'stock_after' => (float) $material->fresh()->current_stock,
                    'rate' => $rate,
                    'adjusted_quantity' => max($qtyIn, $qtyOut),
                ], $user);

                $ledger->update([
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $adjustment->id,
                    'reference_number' => $adjustment->adjustment_number,
                ]);

                return $adjustment;
            }

            $product = $this->lockProduct((int) $data['product_id']);
            $systemStock = (float) $product->current_finished_stock;
            [$qtyIn, $qtyOut] = $this->resolveAdjustmentDelta(
                $adjustmentType,
                $systemStock,
                $adjustedQty,
                (float) ($data['physical_stock'] ?? 0),
            );
            $rate = (float) $product->weighted_average_cost;

            $ledger = $this->ledgerService->postFinishedProductMovement(
                $product,
                $qtyIn,
                $qtyOut,
                $rate,
                [
                    'transaction_date' => $data['adjustment_date'],
                    'transaction_type' => $this->mapAdjustmentTransactionType($adjustmentType),
                    'remarks' => $data['remarks'] ?? $data['reason'] ?? null,
                ],
                $user,
            );

            $adjustment = $this->createAdjustmentRecord($data, $adjustmentType, $itemType, [
                'product_id' => $product->id,
                'system_stock' => $systemStock,
                'stock_after' => (float) $product->fresh()->current_finished_stock,
                'rate' => $rate,
                'adjusted_quantity' => max($qtyIn, $qtyOut),
            ], $user);

            $ledger->update([
                'reference_type' => StockAdjustment::class,
                'reference_id' => $adjustment->id,
                'reference_number' => $adjustment->adjustment_number,
            ]);

            return $adjustment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    private function createAdjustmentRecord(
        array $data,
        StockAdjustmentType $adjustmentType,
        StockItemType $itemType,
        array $extra,
        User $user,
    ): StockAdjustment {
        $qty = (float) $extra['adjusted_quantity'];
        $rate = (float) $extra['rate'];

        return StockAdjustment::query()->create([
            'adjustment_date' => $data['adjustment_date'],
            'adjustment_type' => $adjustmentType,
            'item_type' => $itemType,
            'raw_material_id' => $extra['raw_material_id'] ?? null,
            'packaging_material_id' => $extra['packaging_material_id'] ?? null,
            'product_id' => $extra['product_id'] ?? null,
            'semi_finished_id' => $extra['semi_finished_id'] ?? null,
            'system_stock' => $extra['system_stock'],
            'adjusted_quantity' => $qty,
            'stock_after' => $extra['stock_after'],
            'rate' => $rate,
            'adjustment_value' => round($qty * $rate, 2),
            'reason' => $data['reason'],
            'remarks' => $data['remarks'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'approved_by' => $user->id,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveAdjustmentDelta(
        StockAdjustmentType $type,
        float $systemStock,
        float $adjustedQty,
        float $physicalStock,
    ): array {
        if ($type === StockAdjustmentType::PhysicalStockCorrection) {
            $delta = round($physicalStock - $systemStock, 3);
            if (abs($delta) < 0.0001) {
                throw ValidationException::withMessages([
                    'physical_stock' => 'Physical stock matches system stock. No adjustment required.',
                ]);
            }

            return $delta >= 0 ? [$delta, 0.0] : [0.0, abs($delta)];
        }

        if ($type->increasesStock()) {
            return [$adjustedQty, 0.0];
        }

        $after = round($systemStock - $adjustedQty, 3);
        if ($after < -0.0001) {
            throw ValidationException::withMessages([
                'adjusted_quantity' => "Adjustment would make stock negative. Available: {$systemStock}.",
            ]);
        }

        return [0.0, $adjustedQty];
    }

    private function mapAdjustmentTransactionType(StockAdjustmentType $type): StockTransactionType
    {
        return match ($type) {
            StockAdjustmentType::Damage => StockTransactionType::Damage,
            StockAdjustmentType::Return => StockTransactionType::Return,
            default => StockTransactionType::StockAdjustment,
        };
    }
}
