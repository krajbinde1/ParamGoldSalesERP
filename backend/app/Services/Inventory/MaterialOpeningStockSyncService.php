<?php

namespace App\Services\Inventory;

use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies Opening Stock from material-master Edit without rewriting inward,
 * production, consumption, or adjustment posting. Later movements keep using
 * StockLedgerService current-stock math.
 */
final class MaterialOpeningStockSyncService
{
    public function __construct(
        private readonly RawMaterialCreateService $rawMaterials,
        private readonly PackagingMaterialCreateService $packagingMaterials,
        private readonly SemiFinishedMaterialCreateService $semiFinishedMaterials,
        private readonly FinishedProductCreateService $finishedProducts,
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly MaterialInwardCosting $costing = new MaterialInwardCosting,
    ) {}

    /**
     * @param  array{quantity?: mixed, value?: mixed, date?: mixed}  $opening
     */
    public function syncRawMaterial(RawMaterial $material, array $opening, User $user): void
    {
        $this->sync(
            $opening,
            $user,
            foreignKey: 'raw_material_id',
            recordId: (int) $material->id,
            currentOpeningQty: (float) $material->opening_stock,
            hasOpening: (float) $material->opening_stock > 0 || $this->openingLedger('raw_material_id', (int) $material->id) !== null,
            applyFirstTime: fn () => $this->rawMaterials->applyOpeningStockToExisting($material, $opening, $user),
            updateInPlace: function (StockLedger $ledger, float $qty, float $value, string $date, float $rate) use ($material): void {
                $locked = $this->inventoryService->lockRawMaterial($material->id);
                $locked->opening_stock = $qty;
                $locked->current_stock = $qty;
                $locked->average_rate = $rate;
                $locked->purchase_rate = $rate;
                $locked->current_stock_value = $value;
                $locked->save();
                $this->rewriteOpeningLedger($ledger, $qty, $value, $date, $rate);
            },
            updateDateOnly: function (StockLedger $ledger, string $date): void {
                $ledger->transaction_date = $date;
                $ledger->save();
            },
        );
    }

    /**
     * @param  array{quantity?: mixed, value?: mixed, date?: mixed}  $opening
     */
    public function syncPackagingMaterial(PackagingMaterial $material, array $opening, User $user): void
    {
        $this->sync(
            $opening,
            $user,
            foreignKey: 'packaging_material_id',
            recordId: (int) $material->id,
            currentOpeningQty: (float) $material->opening_stock,
            hasOpening: (float) $material->opening_stock > 0 || $this->openingLedger('packaging_material_id', (int) $material->id) !== null,
            applyFirstTime: fn () => $this->packagingMaterials->applyOpeningStockToExisting($material, $opening, $user),
            updateInPlace: function (StockLedger $ledger, float $qty, float $value, string $date, float $rate) use ($material): void {
                $locked = $this->inventoryService->lockPackagingMaterial($material->id);
                $locked->opening_stock = $qty;
                $locked->current_stock = $qty;
                $locked->average_rate = $rate;
                $locked->purchase_rate = $rate;
                $locked->current_stock_value = $value;
                $locked->save();
                $this->rewriteOpeningLedger($ledger, $qty, $value, $date, $rate);
            },
            updateDateOnly: function (StockLedger $ledger, string $date): void {
                $ledger->transaction_date = $date;
                $ledger->save();
            },
        );
    }

    /**
     * @param  array{quantity?: mixed, value?: mixed, date?: mixed}  $opening
     */
    public function syncSemiFinishedMaterial(SemiFinishedMaterial $material, array $opening, User $user): void
    {
        $this->sync(
            $opening,
            $user,
            foreignKey: 'semi_finished_id',
            recordId: (int) $material->id,
            currentOpeningQty: (float) $material->opening_stock,
            hasOpening: (float) $material->opening_stock > 0 || $this->openingLedger('semi_finished_id', (int) $material->id) !== null,
            applyFirstTime: fn () => $this->semiFinishedMaterials->applyOpeningStockToExisting($material, $opening, $user),
            updateInPlace: function (StockLedger $ledger, float $qty, float $value, string $date, float $rate) use ($material): void {
                $locked = $this->inventoryService->lockSemiFinishedMaterial($material->id);
                $locked->opening_stock = $qty;
                $locked->current_stock = $qty;
                $locked->average_production_cost = $rate;
                $locked->current_stock_value = $value;
                $locked->save();
                $this->rewriteOpeningLedger($ledger, $qty, $value, $date, $rate);
            },
            updateDateOnly: function (StockLedger $ledger, string $date): void {
                $ledger->transaction_date = $date;
                $ledger->save();
            },
        );
    }

    /**
     * @param  array{quantity?: mixed, value?: mixed, date?: mixed}  $opening
     */
    public function syncFinishedProduct(Product $product, array $opening, User $user): void
    {
        $this->sync(
            $opening,
            $user,
            foreignKey: 'product_id',
            recordId: (int) $product->id,
            currentOpeningQty: (float) $product->opening_finished_stock,
            hasOpening: $this->finishedProducts->hasOpeningStock($product),
            applyFirstTime: fn () => $this->finishedProducts->applyOpeningStockToExisting($product, $opening, $user),
            updateInPlace: function (StockLedger $ledger, float $qty, float $value, string $date, float $rate) use ($product): void {
                $locked = $this->inventoryService->lockProduct($product->id);
                $locked->opening_finished_stock = $qty;
                $locked->current_finished_stock = $qty;
                $locked->weighted_average_cost = $rate;
                $locked->manufacturing_enabled = true;
                $locked->save();
                $this->rewriteOpeningLedger($ledger, $qty, $value, $date, $rate);
            },
            updateDateOnly: function (StockLedger $ledger, string $date): void {
                $ledger->transaction_date = $date;
                $ledger->save();
            },
        );
    }

    /**
     * @param  array{quantity?: mixed, value?: mixed, date?: mixed}  $opening
     * @param  \Closure(): Model  $applyFirstTime
     * @param  \Closure(StockLedger, float, float, string, float): void  $updateInPlace
     * @param  \Closure(StockLedger, string): void  $updateDateOnly
     */
    private function sync(
        array $opening,
        User $user,
        string $foreignKey,
        int $recordId,
        float $currentOpeningQty,
        bool $hasOpening,
        \Closure $applyFirstTime,
        \Closure $updateInPlace,
        \Closure $updateDateOnly,
    ): void {
        $qty = round((float) ($opening['quantity'] ?? 0), 3);
        $value = round((float) ($opening['value'] ?? 0), 2);
        $date = filled($opening['date'] ?? null) ? (string) $opening['date'] : null;

        if ($qty < 0) {
            throw ValidationException::withMessages([
                'opening_stock_quantity' => 'Opening Stock Quantity cannot be negative.',
            ]);
        }

        if ($value < 0) {
            throw ValidationException::withMessages([
                'opening_stock_value' => 'Opening Stock Value cannot be negative.',
            ]);
        }

        if ($qty <= 0 && $value > 0) {
            throw ValidationException::withMessages([
                'opening_stock_value' => 'Opening Stock Value must be zero when Opening Stock Quantity is zero.',
            ]);
        }

        $ledger = $this->openingLedger($foreignKey, $recordId);
        $oldValue = $ledger !== null ? round((float) $ledger->transaction_value, 2) : 0.0;
        $oldDate = $ledger?->transaction_date?->toDateString();

        $qtyChanged = abs($qty - $currentOpeningQty) > 0.0005;
        $valueChanged = abs($value - $oldValue) > 0.005;
        $dateChanged = $date !== null && $date !== $oldDate;

        if (! $qtyChanged && ! $valueChanged && ! $dateChanged) {
            return;
        }

        $laterMovements = $this->hasLaterMovements($foreignKey, $recordId);

        if (! $hasOpening) {
            if ($qty <= 0) {
                return;
            }

            $applyFirstTime();

            return;
        }

        if ($laterMovements && ($qtyChanged || $valueChanged)) {
            throw ValidationException::withMessages([
                'opening_stock_quantity' => 'Opening Stock Quantity and Value cannot be changed after inward, production, consumption, or adjustment. Available Stock already follows those transactions. Use Inward, Production, or Stock Adjustment.',
            ]);
        }

        if ($laterMovements && $dateChanged && $ledger !== null && $date !== null) {
            $updateDateOnly($ledger, $date);

            return;
        }

        if ($qty > 0 && $value <= 0) {
            throw ValidationException::withMessages([
                'opening_stock_value' => 'Opening Stock Value is required when Opening Stock Quantity is greater than zero.',
            ]);
        }

        if ($qty > 0 && blank($date)) {
            throw ValidationException::withMessages([
                'opening_date' => 'Opening Date is required when Opening Stock Quantity is greater than zero.',
            ]);
        }

        $rate = $qty > 0 ? (float) $this->costing->calculateItemAmounts([
            'inward_quantity' => $qty,
            'basic_rate' => round($value / $qty, 4),
            'discount_amount' => 0,
            'freight_amount' => 0,
            'other_charges' => 0,
            'gst_percentage' => 0,
        ])['effective_unit_rate'] : 0.0;

        DB::transaction(function () use ($ledger, $qty, $value, $date, $rate, $updateInPlace): void {
            if ($ledger === null) {
                return;
            }

            $updateInPlace($ledger, $qty, $value, $date ?? now('Asia/Kolkata')->toDateString(), $rate);
        });
    }

    private function openingLedger(string $foreignKey, int $recordId): ?StockLedger
    {
        return StockLedger::query()
            ->where($foreignKey, $recordId)
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();
    }

    private function hasLaterMovements(string $foreignKey, int $recordId): bool
    {
        return StockLedger::query()
            ->where($foreignKey, $recordId)
            ->where('transaction_type', '!=', StockTransactionType::OpeningStock)
            ->exists();
    }

    private function rewriteOpeningLedger(
        StockLedger $ledger,
        float $qty,
        float $value,
        string $date,
        float $rate,
    ): void {
        $ledger->transaction_date = $date;
        $ledger->quantity_in = $qty;
        $ledger->quantity_out = 0;
        $ledger->stock_before = 0;
        $ledger->stock_after = $qty;
        $ledger->rate = $rate;
        $ledger->old_average_rate = 0;
        $ledger->new_average_rate = $rate;
        $ledger->average_rate_before = 0;
        $ledger->average_rate_after = $rate;
        $ledger->transaction_value = $value;
        $ledger->inward_value = $value;
        $ledger->outward_value = 0;
        $ledger->opening_value = 0;
        $ledger->closing_value = $value;
        $ledger->save();
    }
}
