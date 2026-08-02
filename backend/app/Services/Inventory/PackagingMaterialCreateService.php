<?php

namespace App\Services\Inventory;

use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a packaging material master with optional opening stock.
 *
 * Opening stock updates packaging inventory + average rate and writes an
 * Opening Stock ledger entry only. It does NOT create Packaging Material
 * Inward records — supplier purchases continue to use the separate
 * Packaging Material Inward module. Never posts into raw material stock.
 */
final class PackagingMaterialCreateService
{
    public function __construct(
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly MaterialInwardCosting $costing = new MaterialInwardCosting,
    ) {}

    /**
     * @param  array<string, mixed>  $materialData
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
    public function create(array $materialData, array $opening, User $user): PackagingMaterial
    {
        return DB::transaction(function () use ($materialData, $opening, $user): PackagingMaterial {
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

            $material = PackagingMaterial::query()->create([
                'packaging_name' => $materialData['packaging_name'],
                'category' => filled($materialData['category'] ?? null) ? $materialData['category'] : 'Other',
                'unit' => $materialData['unit'],
                'minimum_stock' => (float) ($materialData['minimum_stock'] ?? 0),
                'batch_tracking_enabled' => (bool) ($materialData['batch_tracking_enabled'] ?? false),
                'expiry_tracking_enabled' => (bool) ($materialData['expiry_tracking_enabled'] ?? false),
                'status' => (bool) ($materialData['status'] ?? true),
                'remarks' => $materialData['remarks'] ?? null,
                'created_by' => $user->id,
                // Stock starts at zero; opening quantity is applied below when > 0.
                'opening_stock' => 0,
                'current_stock' => 0,
                'current_stock_value' => 0,
                'purchase_rate' => 0,
                'average_rate' => 0,
            ]);

            if ($qty <= 0) {
                return $material->fresh();
            }

            $this->applyOpeningStock($material, $opening, $user);

            return $material->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $opening
     */
    private function applyOpeningStock(PackagingMaterial $material, array $opening, User $user): void
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

        $lockedMaterial = $this->inventoryService->lockPackagingMaterial($material->id);
        $oldStock = (float) $lockedMaterial->current_stock;
        $oldAvg = (float) $lockedMaterial->average_rate;
        $newAvg = $this->costing->calculateWeightedAverageRate($oldStock, $oldAvg, $qty, $effectiveRate);

        $this->ledgerService->postPackagingMaterialMovement(
            $lockedMaterial,
            $qty,
            0,
            $effectiveRate,
            [
                'transaction_date' => $date,
                'transaction_type' => StockTransactionType::OpeningStock,
                'reference_type' => PackagingMaterial::class,
                'reference_id' => $lockedMaterial->id,
                'reference_number' => $lockedMaterial->packaging_code,
                'old_average_rate' => $oldAvg,
                'new_average_rate' => $newAvg,
                'remarks' => $remarks !== '' ? $remarks : 'Packaging Material Creation',
            ],
            $user,
        );

        $lockedMaterial->refresh();
        $lockedMaterial->opening_stock = $qty;
        $lockedMaterial->average_rate = $newAvg;
        $lockedMaterial->purchase_rate = $effectiveRate;
        $lockedMaterial->current_stock_value = round((float) $lockedMaterial->current_stock * $newAvg, 2);
        $lockedMaterial->save();
    }

    /**
     * Resolve Effective Rate via MaterialInwardCosting (same path as raw materials).
     *
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
