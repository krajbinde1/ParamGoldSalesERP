<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Models\FinishedProduct;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finished Goods Inventory enablement / opening stock on an existing Sales Product.
 *
 * Never creates a new Product row. Sales Operations → Products is the single master.
 * Optionally maintains a finished_products sidecar for legacy FP codes (BOM import).
 */
final class FinishedProductCreateService
{
    public function __construct(
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly MaterialInwardCosting $costing = new MaterialInwardCosting,
        private readonly InventoryCodeGenerator $codeGenerator = new InventoryCodeGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $productData
     * @param  array{
     *     quantity?: float|int|string|null,
     *     value?: float|int|string|null,
     *     purchase_rate?: float|int|string|null,
     *     gst_percentage?: float|int|string|null,
     *     freight?: float|int|string|null,
     *     other_charges?: float|int|string|null,
     *     date?: string|null,
     *     remarks?: string|null,
     * }  $opening
     */
    public function create(array $productData, array $opening, User $user): Product
    {
        return DB::transaction(function () use ($productData, $opening, $user): Product {
            $qty = round((float) ($opening['quantity'] ?? 0), 3);
            $value = round((float) ($opening['value'] ?? 0), 2);

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

            $linkedProductId = isset($productData['linked_product_id']) && filled($productData['linked_product_id'])
                ? (int) $productData['linked_product_id']
                : null;

            if ($linkedProductId === null) {
                throw ValidationException::withMessages([
                    'linked_product_id' => 'Select an existing Sales Product. Finished Goods Inventory cannot create new products.',
                ]);
            }

            $unit = (string) ($productData['unit'] ?? $productData['production_unit'] ?? '');
            if ($unit === '') {
                throw ValidationException::withMessages([
                    'unit' => 'Unit is required.',
                ]);
            }

            $product = $this->enableInventoryOnExistingProduct($linkedProductId, $productData, $unit);

            // Optional sidecar for legacy FP codes / BOM import compatibility.
            $this->ensureFinishedProductMaster($product, $unit, $productData, $user);

            if ($qty <= 0) {
                return $product->fresh(['finishedProduct']);
            }

            if ($this->hasOpeningStock($product)) {
                throw ValidationException::withMessages([
                    'opening_stock_quantity' => 'Opening stock already exists for this Finished Product.',
                ]);
            }

            $this->applyOpeningStock($product, $opening, $user);

            return $product->fresh(['finishedProduct']);
        });
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function enableInventoryOnExistingProduct(int $linkedProductId, array $productData, string $unit): Product
    {
        $product = Product::query()->whereKey($linkedProductId)->lockForUpdate()->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'linked_product_id' => 'Selected sales product was not found.',
            ]);
        }

        // Sales product_code, name, and pricing are never changed here.
        $updates = [
            'minimum_finished_stock' => (float) ($productData['minimum_finished_stock'] ?? $product->minimum_finished_stock ?? 0),
            'batch_tracking_enabled' => (bool) ($productData['batch_tracking_enabled'] ?? $product->batch_tracking_enabled ?? true),
            'expiry_tracking_enabled' => (bool) ($productData['expiry_tracking_enabled'] ?? $product->expiry_tracking_enabled ?? false),
            'status' => (bool) ($productData['status'] ?? $product->status ?? true),
            'remarks' => array_key_exists('remarks', $productData)
                ? ($productData['remarks'] ?? null)
                : $product->remarks,
            'manufacturing_enabled' => true,
        ];

        if (! $product->hasFinishedStockTransactions()) {
            $updates['production_unit'] = $unit;
        }

        $product->fill($updates);
        $product->save();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function ensureFinishedProductMaster(Product $product, string $unit, array $productData, User $user): FinishedProduct
    {
        $existing = FinishedProduct::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return FinishedProduct::query()->create([
            'finished_product_code' => $this->codeGenerator->nextFinishedProductCode(),
            'product_id' => $product->id,
            'unit' => $unit,
            'minimum_stock' => (float) ($productData['minimum_finished_stock'] ?? $product->minimum_finished_stock ?? 0),
            'status' => (bool) ($productData['status'] ?? $product->status ?? true),
            'remarks' => $productData['remarks'] ?? $product->remarks,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Apply opening stock to an existing Sales Product's finished-goods inventory.
     *
     * Does not create Product rows. Blocks when opening stock already exists.
     * Posts Opening Stock ledger via existing FG inventory logic (not production / inward).
     *
     * @param  array{
     *     quantity?: float|int|string|null,
     *     value?: float|int|string|null,
     *     purchase_rate?: float|int|string|null,
     *     gst_percentage?: float|int|string|null,
     *     freight?: float|int|string|null,
     *     other_charges?: float|int|string|null,
     *     date?: string|null,
     *     remarks?: string|null,
     * }  $opening
     */
    public function applyOpeningStockToExisting(Product $product, array $opening, User $user): Product
    {
        return DB::transaction(function () use ($product, $opening, $user): Product {
            $locked = $this->inventoryService->lockProduct($product->id);

            if ($this->hasOpeningStock($locked)) {
                throw ValidationException::withMessages([
                    'opening_stock_quantity' => 'Opening stock already exists for this Finished Product.',
                ]);
            }

            $qty = round((float) ($opening['quantity'] ?? 0), 3);
            $value = round((float) ($opening['value'] ?? 0), 2);

            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    'opening_stock_quantity' => 'Opening Stock Quantity must be greater than zero.',
                ]);
            }

            if ($value < 0) {
                throw ValidationException::withMessages([
                    'opening_stock_value' => 'Opening Stock Value cannot be negative.',
                ]);
            }

            $locked->manufacturing_enabled = true;
            $locked->save();

            $opening['remarks'] = filled($opening['remarks'] ?? null)
                ? (string) $opening['remarks']
                : 'Opening Stock';

            $this->applyOpeningStock($locked, $opening, $user);

            return $locked->fresh();
        });
    }

    public function hasOpeningStock(Product $product): bool
    {
        if ((float) $product->opening_finished_stock > 0) {
            return true;
        }

        return StockLedger::query()
            ->where('product_id', $product->id)
            ->where('item_type', StockItemType::FinishedProduct)
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $opening
     */
    private function applyOpeningStock(Product $product, array $opening, User $user): void
    {
        $qty = round((float) ($opening['quantity'] ?? 0), 3);
        $date = $opening['date'] ?? now('Asia/Kolkata')->toDateString();
        $remarks = trim((string) ($opening['remarks'] ?? ''));

        if (blank($date)) {
            throw ValidationException::withMessages([
                'opening_date' => 'Opening Date is required when Opening Stock Quantity is greater than zero.',
            ]);
        }

        $calculated = $this->resolveOpeningCosting($qty, $opening);
        $effectiveRate = (float) $calculated['effective_unit_rate'];

        $lockedProduct = $this->inventoryService->lockProduct($product->id);
        $oldStock = (float) $lockedProduct->current_finished_stock;
        $oldAvg = (float) $lockedProduct->weighted_average_cost;
        $newAvg = $this->costing->calculateWeightedAverageRate($oldStock, $oldAvg, $qty, $effectiveRate);

        $this->ledgerService->postFinishedProductMovement(
            $lockedProduct,
            $qty,
            0,
            $effectiveRate,
            [
                'transaction_date' => $date,
                'transaction_type' => StockTransactionType::OpeningStock,
                'reference_type' => Product::class,
                'reference_id' => $lockedProduct->id,
                'reference_number' => $lockedProduct->product_code,
                'old_average_rate' => $oldAvg,
                'new_average_rate' => $newAvg,
                'remarks' => $remarks !== '' ? $remarks : 'Opening Stock',
            ],
            $user,
        );

        $lockedProduct->refresh();
        $lockedProduct->opening_finished_stock = $qty;
        $lockedProduct->weighted_average_cost = $newAvg;
        $lockedProduct->manufacturing_enabled = true;
        $lockedProduct->save();
    }

    /**
     * @param  array<string, mixed>  $opening
     * @return array<string, mixed>
     */
    private function resolveOpeningCosting(float $qty, array $opening): array
    {
        $hasExplicitValue = array_key_exists('value', $opening)
            && $opening['value'] !== null
            && $opening['value'] !== '';

        if ($hasExplicitValue) {
            $value = round((float) $opening['value'], 2);

            if ($value <= 0) {
                throw ValidationException::withMessages([
                    'opening_stock_value' => 'Opening Stock Value must be greater than zero when Opening Stock Quantity is greater than zero.',
                ]);
            }

            $basicRate = round($value / $qty, 4);

            return $this->costing->calculateItemAmounts([
                'inward_quantity' => $qty,
                'basic_rate' => $basicRate,
                'discount_amount' => 0,
                'freight_amount' => 0,
                'other_charges' => 0,
                'gst_percentage' => 0,
            ]);
        }

        $rate = round((float) ($opening['purchase_rate'] ?? 0), 4);
        $gst = round((float) ($opening['gst_percentage'] ?? 0), 2);
        $freight = round((float) ($opening['freight'] ?? 0), 2);
        $other = round((float) ($opening['other_charges'] ?? 0), 2);

        if ($rate <= 0) {
            throw ValidationException::withMessages([
                'opening_stock_value' => 'Opening Stock Value must be greater than zero when Opening Stock Quantity is greater than zero.',
            ]);
        }

        if ($freight < 0 || $other < 0 || $gst < 0) {
            throw ValidationException::withMessages([
                'opening_stock_value' => 'Opening stock charges cannot be negative.',
            ]);
        }

        return $this->costing->calculateItemAmounts([
            'inward_quantity' => $qty,
            'basic_rate' => $rate,
            'discount_amount' => 0,
            'freight_amount' => $freight,
            'other_charges' => $other,
            'gst_percentage' => $gst,
        ]);
    }
}
