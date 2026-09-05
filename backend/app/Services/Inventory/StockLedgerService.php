<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class StockLedgerService
{
    public function record(array $data, ?User $user = null): StockLedger
    {
        return StockLedger::query()->create([
            ...$data,
            'created_by' => $user?->id ?? $data['created_by'] ?? null,
        ]);
    }

    /**
     * @param  array{transaction_date: mixed, transaction_type: StockTransactionType|string, reference_type?: ?string, reference_id?: ?int, reference_number?: ?string, batch_number?: ?string, remarks?: ?string, old_average_rate?: float|int|string|null, new_average_rate?: float|int|string|null, supplier_invoice_number?: ?string}  $meta
     */
    public function postRawMaterialMovement(
        RawMaterial $material,
        float $quantityIn,
        float $quantityOut,
        float $rate,
        array $meta,
        ?User $user = null,
    ): StockLedger {
        $stockBefore = (float) $material->current_stock;
        $stockAfter = round($stockBefore + $quantityIn - $quantityOut, 3);

        if ($stockAfter < -0.0001) {
            throw ValidationException::withMessages([
                'stock' => "Insufficient stock for raw material {$material->material_name}. Available: {$stockBefore}.",
            ]);
        }

        $stockAfter = max(0, $stockAfter);
        $averageBefore = (float) $material->average_rate;
        $this->applyWeightedAverageStock($material, $stockAfter, $meta);

        $snapshots = $this->buildValueSnapshots(
            stockBefore: $stockBefore,
            stockAfter: $stockAfter,
            quantityIn: $quantityIn,
            quantityOut: $quantityOut,
            rate: $rate,
            fallbackAverageBefore: $averageBefore,
            meta: $meta,
        );

        return $this->record([
            'transaction_date' => $meta['transaction_date'],
            'transaction_type' => $meta['transaction_type'],
            'item_type' => StockItemType::RawMaterial,
            'raw_material_id' => $material->id,
            'packaging_material_id' => null,
            'product_id' => null,
            'semi_finished_id' => null,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'reference_number' => $meta['reference_number'] ?? null,
            'supplier_invoice_number' => $meta['supplier_invoice_number'] ?? null,
            'batch_number' => $meta['batch_number'] ?? null,
            'quantity_in' => round($quantityIn, 3),
            'quantity_out' => round($quantityOut, 3),
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'rate' => round($rate, 4),
            'old_average_rate' => isset($meta['old_average_rate']) ? round((float) $meta['old_average_rate'], 4) : null,
            'new_average_rate' => isset($meta['new_average_rate']) ? round((float) $meta['new_average_rate'], 4) : null,
            'transaction_value' => round(max($quantityIn, $quantityOut) * $rate, 2),
            ...$snapshots,
            'remarks' => $meta['remarks'] ?? null,
        ], $user);
    }

    /**
     * @param  array{transaction_date: mixed, transaction_type: StockTransactionType|string, reference_type?: ?string, reference_id?: ?int, reference_number?: ?string, batch_number?: ?string, remarks?: ?string, old_average_rate?: float|int|string|null, new_average_rate?: float|int|string|null, supplier_invoice_number?: ?string}  $meta
     */
    public function postPackagingMaterialMovement(
        PackagingMaterial $material,
        float $quantityIn,
        float $quantityOut,
        float $rate,
        array $meta,
        ?User $user = null,
    ): StockLedger {
        $stockBefore = (float) $material->current_stock;
        $stockAfter = round($stockBefore + $quantityIn - $quantityOut, 3);

        if ($stockAfter < -0.0001) {
            throw ValidationException::withMessages([
                'stock' => "Insufficient stock for packaging material {$material->packaging_name}. Available: {$stockBefore}.",
            ]);
        }

        $stockAfter = max(0, $stockAfter);
        $averageBefore = (float) $material->average_rate;
        $this->applyWeightedAverageStock($material, $stockAfter, $meta);

        $snapshots = $this->buildValueSnapshots(
            stockBefore: $stockBefore,
            stockAfter: $stockAfter,
            quantityIn: $quantityIn,
            quantityOut: $quantityOut,
            rate: $rate,
            fallbackAverageBefore: $averageBefore,
            meta: $meta,
        );

        return $this->record([
            'transaction_date' => $meta['transaction_date'],
            'transaction_type' => $meta['transaction_type'],
            'item_type' => StockItemType::PackagingMaterial,
            'raw_material_id' => null,
            'packaging_material_id' => $material->id,
            'product_id' => null,
            'semi_finished_id' => null,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'reference_number' => $meta['reference_number'] ?? null,
            'supplier_invoice_number' => $meta['supplier_invoice_number'] ?? null,
            'batch_number' => $meta['batch_number'] ?? null,
            'quantity_in' => round($quantityIn, 3),
            'quantity_out' => round($quantityOut, 3),
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'rate' => round($rate, 4),
            'old_average_rate' => isset($meta['old_average_rate']) ? round((float) $meta['old_average_rate'], 4) : null,
            'new_average_rate' => isset($meta['new_average_rate']) ? round((float) $meta['new_average_rate'], 4) : null,
            'transaction_value' => round(max($quantityIn, $quantityOut) * $rate, 2),
            ...$snapshots,
            'remarks' => $meta['remarks'] ?? null,
        ], $user);
    }

    /**
     * @param  array{
     *     transaction_date: mixed,
     *     transaction_type: StockTransactionType|string,
     *     reference_type?: ?string,
     *     reference_id?: ?int,
     *     reference_number?: ?string,
     *     batch_number?: ?string,
     *     remarks?: ?string,
     *     old_average_rate?: float|int|string|null,
     *     new_average_rate?: float|int|string|null,
     *     inward_value?: float|int|string|null,
     *     outward_value?: float|int|string|null,
     *     opening_value?: float|int|string|null,
     *     closing_value?: float|int|string|null,
     *     transaction_value?: float|int|string|null
     * }  $meta
     */
    public function postFinishedProductMovement(
        Product $product,
        float $quantityIn,
        float $quantityOut,
        float $rate,
        array $meta,
        ?User $user = null,
    ): StockLedger {
        $stockBefore = (float) $product->current_finished_stock;
        $stockAfter = round($stockBefore + $quantityIn - $quantityOut, 3);

        if ($stockAfter < -0.0001) {
            throw ValidationException::withMessages([
                'stock' => "Insufficient finished stock for {$product->product_name}. Available: {$stockBefore}.",
            ]);
        }

        $stockAfter = max(0, $stockAfter);
        $product->current_finished_stock = $stockAfter;
        $product->save();

        $snapshots = $this->buildValueSnapshots(
            stockBefore: $stockBefore,
            stockAfter: $stockAfter,
            quantityIn: $quantityIn,
            quantityOut: $quantityOut,
            rate: $rate,
            fallbackAverageBefore: (float) $product->weighted_average_cost,
            meta: $meta,
        );

        $transactionValue = isset($meta['transaction_value'])
            ? round((float) $meta['transaction_value'], 2)
            : round(max($quantityIn, $quantityOut) * $rate, 2);

        return $this->record([
            'transaction_date' => $meta['transaction_date'],
            'transaction_type' => $meta['transaction_type'],
            'item_type' => StockItemType::FinishedProduct,
            'raw_material_id' => null,
            'packaging_material_id' => null,
            'product_id' => $product->id,
            'semi_finished_id' => null,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'reference_number' => $meta['reference_number'] ?? null,
            'batch_number' => $meta['batch_number'] ?? null,
            'quantity_in' => round($quantityIn, 3),
            'quantity_out' => round($quantityOut, 3),
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'rate' => round($rate, 4),
            'old_average_rate' => isset($meta['old_average_rate']) ? round((float) $meta['old_average_rate'], 4) : $snapshots['average_rate_before'],
            'new_average_rate' => isset($meta['new_average_rate']) ? round((float) $meta['new_average_rate'], 4) : $snapshots['average_rate_after'],
            'transaction_value' => $transactionValue,
            ...$snapshots,
            'remarks' => $meta['remarks'] ?? null,
        ], $user);
    }

    /**
     * @param  array{
     *     transaction_date: mixed,
     *     transaction_type: StockTransactionType|string,
     *     reference_type?: ?string,
     *     reference_id?: ?int,
     *     reference_number?: ?string,
     *     batch_number?: ?string,
     *     remarks?: ?string,
     *     old_average_rate?: float|int|string|null,
     *     new_average_rate?: float|int|string|null,
     *     inward_value?: float|int|string|null,
     *     outward_value?: float|int|string|null,
     *     opening_value?: float|int|string|null,
     *     closing_value?: float|int|string|null,
     *     transaction_value?: float|int|string|null
     * }  $meta
     */
    public function postSemiFinishedMovement(
        SemiFinishedMaterial $material,
        float $quantityIn,
        float $quantityOut,
        float $rate,
        array $meta,
        ?User $user = null,
    ): StockLedger {
        $stockBefore = (float) $material->current_stock;
        $stockAfter = round($stockBefore + $quantityIn - $quantityOut, 3);

        if ($stockAfter < -0.0001) {
            throw ValidationException::withMessages([
                'stock' => "Insufficient stock for semi-finished material {$material->material_name}. Available: {$stockBefore}.",
            ]);
        }

        $stockAfter = max(0, $stockAfter);
        $avgRate = (float) $material->average_production_cost;

        // Outward consumption/adjustment: keep average production cost unchanged.
        // Inward production WAVG is applied by SemiFinishedPostingService after this call.
        if ($quantityOut > 0 && $quantityIn <= 0) {
            $material->current_stock = $stockAfter;
            $material->current_stock_value = round($stockAfter * $avgRate, 2);
            $material->save();
        } elseif ($quantityIn > 0 && isset($meta['new_average_rate'])) {
            $material->current_stock = $stockAfter;
            $material->average_production_cost = round((float) $meta['new_average_rate'], 4);
            $material->current_stock_value = isset($meta['closing_value'])
                ? round((float) $meta['closing_value'], 2)
                : round($stockAfter * (float) $material->average_production_cost, 2);
            $material->save();
        } else {
            $material->current_stock = $stockAfter;
            $material->current_stock_value = round($stockAfter * $avgRate, 2);
            $material->save();
        }

        $snapshots = $this->buildValueSnapshots(
            stockBefore: $stockBefore,
            stockAfter: $stockAfter,
            quantityIn: $quantityIn,
            quantityOut: $quantityOut,
            rate: $rate,
            fallbackAverageBefore: $avgRate,
            meta: $meta,
        );

        $transactionValue = isset($meta['transaction_value'])
            ? round((float) $meta['transaction_value'], 2)
            : round(max($quantityIn, $quantityOut) * $rate, 2);

        return $this->record([
            'transaction_date' => $meta['transaction_date'],
            'transaction_type' => $meta['transaction_type'],
            'item_type' => StockItemType::SemiFinished,
            'raw_material_id' => null,
            'packaging_material_id' => null,
            'product_id' => null,
            'semi_finished_id' => $material->id,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'reference_number' => $meta['reference_number'] ?? null,
            'batch_number' => $meta['batch_number'] ?? null,
            'quantity_in' => round($quantityIn, 3),
            'quantity_out' => round($quantityOut, 3),
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'rate' => round($rate, 4),
            'old_average_rate' => isset($meta['old_average_rate']) ? round((float) $meta['old_average_rate'], 4) : $snapshots['average_rate_before'],
            'new_average_rate' => isset($meta['new_average_rate']) ? round((float) $meta['new_average_rate'], 4) : $snapshots['average_rate_after'],
            'transaction_value' => $transactionValue,
            ...$snapshots,
            'remarks' => $meta['remarks'] ?? null,
        ], $user);
    }

    public function createOpeningStockEntry(Model $item, ?User $user = null): ?StockLedger
    {
        if ($item instanceof RawMaterial) {
            $qty = (float) $item->opening_stock;
            if ($qty <= 0) {
                return null;
            }

            $rate = (float) $item->average_rate;
            $value = round($qty * $rate, 2);

            return $this->record([
                'transaction_date' => now('Asia/Kolkata')->toDateString(),
                'transaction_type' => StockTransactionType::OpeningStock,
                'item_type' => StockItemType::RawMaterial,
                'raw_material_id' => $item->id,
                'quantity_in' => $qty,
                'quantity_out' => 0,
                'stock_before' => 0,
                'stock_after' => $qty,
                'opening_value' => 0,
                'closing_value' => $value,
                'average_rate_before' => 0,
                'average_rate_after' => $rate,
                'rate' => $rate,
                'transaction_value' => $value,
                'inward_value' => $value,
                'outward_value' => 0,
                'remarks' => 'Opening stock',
            ], $user);
        }

        if ($item instanceof PackagingMaterial) {
            $qty = (float) $item->opening_stock;
            if ($qty <= 0) {
                return null;
            }

            $rate = (float) $item->average_rate;
            $value = round($qty * $rate, 2);

            return $this->record([
                'transaction_date' => now('Asia/Kolkata')->toDateString(),
                'transaction_type' => StockTransactionType::OpeningStock,
                'item_type' => StockItemType::PackagingMaterial,
                'packaging_material_id' => $item->id,
                'quantity_in' => $qty,
                'quantity_out' => 0,
                'stock_before' => 0,
                'stock_after' => $qty,
                'opening_value' => 0,
                'closing_value' => $value,
                'average_rate_before' => 0,
                'average_rate_after' => $rate,
                'rate' => $rate,
                'transaction_value' => $value,
                'inward_value' => $value,
                'outward_value' => 0,
                'remarks' => 'Opening stock',
            ], $user);
        }

        return null;
    }

    /**
     * Optional value/rate snapshots for new ledger rows only — does not alter qty posting.
     *
     * @param  array<string, mixed>  $meta
     * @return array{
     *     opening_value: float,
     *     closing_value: float,
     *     average_rate_before: float,
     *     average_rate_after: float,
     *     inward_value: float,
     *     outward_value: float
     * }
     */
    /**
     * Apply live quantity, GST-exclusive weighted average rate, and stock value.
     *
     * @param  array<string, mixed>  $meta
     */
    private function applyWeightedAverageStock(
        RawMaterial|PackagingMaterial $material,
        float $stockAfter,
        array $meta,
    ): void {
        if (array_key_exists('new_average_rate', $meta) && $meta['new_average_rate'] !== null) {
            $material->average_rate = round((float) $meta['new_average_rate'], 4);
        }

        $material->current_stock = $stockAfter;
        $material->current_stock_value = round($stockAfter * (float) $material->average_rate, 2);
        $material->save();
    }

    private function buildValueSnapshots(
        float $stockBefore,
        float $stockAfter,
        float $quantityIn,
        float $quantityOut,
        float $rate,
        float $fallbackAverageBefore,
        array $meta,
    ): array {
        $avgBefore = isset($meta['old_average_rate'])
            ? (float) $meta['old_average_rate']
            : ($quantityOut > 0 ? $rate : $fallbackAverageBefore);

        $inwardValue = array_key_exists('inward_value', $meta) && $meta['inward_value'] !== null
            ? round((float) $meta['inward_value'], 2)
            : ($quantityIn > 0 ? round($quantityIn * $rate, 2) : 0.0);
        $outwardValue = array_key_exists('outward_value', $meta) && $meta['outward_value'] !== null
            ? round((float) $meta['outward_value'], 2)
            : ($quantityOut > 0 ? round($quantityOut * $avgBefore, 2) : 0.0);
        $openingValue = array_key_exists('opening_value', $meta) && $meta['opening_value'] !== null
            ? round((float) $meta['opening_value'], 2)
            : round(max(0, $stockBefore) * max(0, $avgBefore), 2);
        $closingValue = array_key_exists('closing_value', $meta) && $meta['closing_value'] !== null
            ? round((float) $meta['closing_value'], 2)
            : round(max(0, $openingValue + $inwardValue - $outwardValue), 2);

        if ($stockAfter <= 0.0001) {
            $closingValue = 0.0;
            $avgAfter = 0.0;
        } else {
            $avgAfter = isset($meta['new_average_rate'])
                ? (float) $meta['new_average_rate']
                : round($closingValue / $stockAfter, 4);
        }

        return [
            'opening_value' => $openingValue,
            'closing_value' => $closingValue,
            'average_rate_before' => round($avgBefore, 4),
            'average_rate_after' => round($avgAfter, 4),
            'inward_value' => $inwardValue,
            'outward_value' => $outwardValue,
        ];
    }
}
