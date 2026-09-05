<?php

namespace App\Services\Inventory;

use App\Enums\PurchaseMaterialType;
use App\Enums\PurchaseStatus;
use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseService
{
    public function __construct(
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly MaterialInwardCosting $costing = new MaterialInwardCosting,
        private readonly WeightedAverageCosting $weightedAverage = new WeightedAverageCosting,
        private readonly InventoryCodeGenerator $codes = new InventoryCodeGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $header, array $items, User $user, bool $confirm = false): Purchase
    {
        $this->assertCanCreate($user);

        return DB::transaction(function () use ($header, $items, $user, $confirm) {
            $purchase = Purchase::query()->create([
                ...$this->normalizeHeader($header),
                'purchase_number' => $this->codes->nextPurchaseNumber(),
                'status' => PurchaseStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->syncItems($purchase, $items);
            $this->recalculateHeaderTotals($purchase);

            $fresh = $purchase->fresh(['items', 'supplier', 'createdBy']);

            return $confirm ? $this->confirmLocked($fresh, $user) : $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function update(Purchase $purchase, array $header, array $items, User $user): Purchase
    {
        return DB::transaction(function () use ($purchase, $header, $items, $user) {
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PurchaseStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'Cancelled purchases cannot be edited.',
                ]);
            }

            if ($locked->status === PurchaseStatus::Confirmed) {
                $this->assertCanUpdate($user);

                return $this->updateConfirmedSafely($locked, $header, $items, $user);
            }

            $this->assertCanCreate($user);

            $locked->fill($this->normalizeHeader($header));
            $locked->save();
            $locked->items()->delete();
            $this->syncItems($locked, $items);
            $this->recalculateHeaderTotals($locked);

            return $locked->fresh(['items', 'supplier', 'createdBy']);
        });
    }

    public function confirm(Purchase $purchase, User $user): Purchase
    {
        $this->assertCanCreate($user);

        return DB::transaction(function () use ($purchase, $user) {
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            return $this->confirmLocked($locked, $user);
        });
    }

    public function cancel(Purchase $purchase, User $user, ?string $reason = null): Purchase
    {
        if (! $user->canCancelPurchase()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to cancel purchases.',
            ]);
        }

        return DB::transaction(function () use ($purchase, $user, $reason) {
            $locked = Purchase::query()->with('items')->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => 'This purchase cannot be cancelled.',
                ]);
            }

            if ($locked->status === PurchaseStatus::Confirmed) {
                $this->assertCanReverseStock($locked);
                $this->reverseConfirmedStock($locked, $user, 'Purchase cancel — '.$locked->purchase_number);
            }

            $locked->status = PurchaseStatus::Cancelled;
            $locked->cancelled_by = $user->id;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            return $locked->fresh(['items', 'supplier']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    private function updateConfirmedSafely(Purchase $locked, array $header, array $items, User $user): Purchase
    {
        $this->assertCanReverseStock($locked);
        $this->reverseConfirmedStock($locked, $user, 'Purchase edit reversal — '.$locked->purchase_number);

        $locked->refresh();
        $locked->status = PurchaseStatus::Draft;
        $locked->confirmed_at = null;
        $locked->confirmed_by = null;
        $locked->fill($this->normalizeHeader($header));
        $locked->save();

        $locked->items()->delete();
        $this->syncItems($locked, $items);
        $this->recalculateHeaderTotals($locked);

        return $this->confirmLocked($locked->fresh(['items']), $user);
    }

    private function confirmLocked(Purchase $locked, User $user): Purchase
    {
        if ($locked->status === PurchaseStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => 'This purchase is already confirmed.',
            ]);
        }

        if ($locked->status === PurchaseStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled purchases cannot be confirmed.',
            ]);
        }

        $this->assertHasItems($locked);
        $items = $locked->items()->get();

        foreach ($items as $item) {
            $this->postItem($locked, $item, $user);
        }

        $locked->status = PurchaseStatus::Confirmed;
        $locked->confirmed_by = $user->id;
        $locked->confirmed_at = now();
        $locked->save();

        return $locked->fresh(['items', 'supplier', 'createdBy']);
    }

    private function postItem(Purchase $purchase, PurchaseItem $item, User $user): void
    {
        $calculated = $this->costing->calculateItemAmounts([
            'inward_quantity' => (float) $item->quantity,
            'basic_rate' => (float) $item->purchase_rate,
            'gst_percentage' => (float) $item->gst_percentage,
        ]);

        $qty = (float) $calculated['accepted_quantity'];
        $purchaseRate = (float) $item->purchase_rate;

        if ($purchase->material_type === PurchaseMaterialType::RawMaterial) {
            $material = $this->inventoryService->lockRawMaterial((int) $item->raw_material_id);
            $this->applyInwardToMaterial($purchase, $item, $user, $material, $qty, $purchaseRate, $calculated);
        } else {
            $material = $this->inventoryService->lockPackagingMaterial((int) $item->packaging_material_id);
            $this->applyInwardToMaterial($purchase, $item, $user, $material, $qty, $purchaseRate, $calculated);
        }
    }

    /**
     * @param  RawMaterial|PackagingMaterial  $material
     * @param  array<string, mixed>  $calculated
     */
    private function applyInwardToMaterial(
        Purchase $purchase,
        PurchaseItem $item,
        User $user,
        RawMaterial|PackagingMaterial $material,
        float $qty,
        float $purchaseRate,
        array $calculated,
    ): void {
        $oldStock = (float) $material->current_stock;
        $oldAvg = (float) $material->average_rate;
        $stockAfter = round($oldStock + $qty, 3);
        $newAvg = $qty > 0
            ? $this->weightedAverage->newAverageRate($oldStock, $oldAvg, $qty, $purchaseRate)
            : $oldAvg;
        $stockValue = $this->weightedAverage->stockValue($stockAfter, $newAvg);
        $inwardValue = $this->weightedAverage->stockValue($qty, $purchaseRate);

        $item->fill([
            'taxable_amount' => $calculated['taxable_amount'],
            'gst_amount' => $calculated['igst_amount'],
            'total_amount' => $calculated['total_amount'],
            'landed_cost' => $calculated['landed_cost'],
            'effective_unit_rate' => $calculated['effective_unit_rate'],
            'stock_before' => $oldStock,
            'stock_after' => $stockAfter,
            'old_average_rate' => $oldAvg,
            'new_average_rate' => $newAvg,
        ]);
        $item->save();

        if ($qty <= 0) {
            return;
        }

        $meta = $this->ledgerMeta($purchase, $item, $oldAvg, $newAvg, $purchaseRate, $inwardValue);

        if ($material instanceof RawMaterial) {
            $this->ledgerService->postRawMaterialMovement($material, $qty, 0, $purchaseRate, $meta, $user);
        } else {
            $this->ledgerService->postPackagingMaterialMovement($material, $qty, 0, $purchaseRate, $meta, $user);
        }

        $material->refresh();
        $material->average_rate = $newAvg;
        $material->purchase_rate = $purchaseRate > 0 ? $purchaseRate : $material->purchase_rate;
        $material->current_stock_value = $stockValue;
        $material->save();
    }

    private function reverseConfirmedStock(Purchase $locked, User $user, string $remarks): void
    {
        $items = $locked->items()->orderByDesc('sort_order')->get();

        foreach ($items as $item) {
            $qty = (float) $item->quantity;
            if ($qty <= 0) {
                continue;
            }

            $purchaseRate = (float) $item->purchase_rate;
            $restoreAvg = $item->old_average_rate !== null
                ? (float) $item->old_average_rate
                : null;

            $meta = [
                'transaction_date' => now('Asia/Kolkata')->toDateString(),
                'transaction_type' => StockTransactionType::PurchaseReturn,
                'reference_type' => Purchase::class,
                'reference_id' => $locked->id,
                'reference_number' => $locked->purchase_number,
                'supplier_invoice_number' => $locked->supplier_invoice_number,
                'batch_number' => $item->batch_lot_no,
                'remarks' => $remarks,
            ];

            if ($item->raw_material_id) {
                $material = $this->inventoryService->lockRawMaterial((int) $item->raw_material_id);
                if ((float) $material->current_stock + 0.0001 < $qty) {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot reverse this purchase because subsequent stock transactions exist.',
                    ]);
                }
                $meta['old_average_rate'] = (float) $material->average_rate;
                $meta['new_average_rate'] = $restoreAvg ?? (float) $material->average_rate;
                $this->ledgerService->postRawMaterialMovement($material, 0, $qty, $purchaseRate, $meta, $user);
            } else {
                $material = $this->inventoryService->lockPackagingMaterial((int) $item->packaging_material_id);
                if ((float) $material->current_stock + 0.0001 < $qty) {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot reverse this purchase because subsequent stock transactions exist.',
                    ]);
                }
                $meta['old_average_rate'] = (float) $material->average_rate;
                $meta['new_average_rate'] = $restoreAvg ?? (float) $material->average_rate;
                $this->ledgerService->postPackagingMaterialMovement($material, 0, $qty, $purchaseRate, $meta, $user);
            }

            $material->refresh();
            $restoredAvg = $restoreAvg ?? (float) $material->average_rate;
            $material->average_rate = $restoredAvg;
            $material->current_stock_value = $this->weightedAverage->stockValue(
                (float) $material->current_stock,
                $restoredAvg,
            );
            $material->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(Purchase $purchase, array $items): void
    {
        $type = $purchase->material_type;
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one material line is required.',
            ]);
        }

        foreach ($items as $index => $item) {
            $rawId = isset($item['raw_material_id']) ? (int) $item['raw_material_id'] : 0;
            $packId = isset($item['packaging_material_id']) ? (int) $item['packaging_material_id'] : 0;

            if ($type === PurchaseMaterialType::RawMaterial) {
                if ($rawId <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Select a raw material from the existing master.',
                    ]);
                }
                $material = RawMaterial::query()->where('status', true)->find($rawId);
                if ($material === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Purchase items must be selected from active raw material masters.',
                    ]);
                }
                $unit = $material->unit;
                $packId = null;
            } else {
                if ($packId <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Select a packing material from the existing master.',
                    ]);
                }
                $material = PackagingMaterial::query()->where('status', true)->find($packId);
                if ($material === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Purchase items must be selected from active packing material masters.',
                    ]);
                }
                $unit = $material->unit;
                $rawId = null;
            }

            $calculated = $this->costing->calculateItemAmounts([
                'inward_quantity' => $item['quantity'] ?? 0,
                'basic_rate' => $item['purchase_rate'] ?? 0,
                'gst_percentage' => $item['gst_percentage'] ?? 0,
            ]);

            PurchaseItem::query()->create([
                'purchase_id' => $purchase->id,
                'raw_material_id' => $rawId,
                'packaging_material_id' => $packId,
                'unit' => $unit,
                'quantity' => $calculated['accepted_quantity'],
                'purchase_rate' => $calculated['basic_rate'],
                'taxable_amount' => $calculated['taxable_amount'],
                'gst_percentage' => $calculated['gst_percentage'],
                'gst_amount' => $calculated['igst_amount'],
                'total_amount' => $calculated['total_amount'],
                'landed_cost' => $calculated['landed_cost'],
                'effective_unit_rate' => $calculated['effective_unit_rate'],
                'batch_lot_no' => filled($item['batch_lot_no'] ?? null) ? trim((string) $item['batch_lot_no']) : null,
                'remarks' => $item['remarks'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    private function recalculateHeaderTotals(Purchase $purchase): void
    {
        $items = $purchase->items()->get();

        $purchase->total_items = $items->count();
        $purchase->total_quantity = round($items->sum('quantity'), 3);
        $purchase->total_taxable_amount = round($items->sum('taxable_amount'), 2);
        $purchase->total_gst = round($items->sum('gst_amount'), 2);
        $purchase->grand_total = round($items->sum('total_amount'), 2);
        $purchase->save();
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $header): array
    {
        $type = $header['material_type'] ?? null;
        if (! $type instanceof PurchaseMaterialType) {
            $type = PurchaseMaterialType::tryFrom((string) $type);
        }
        if ($type === null) {
            throw ValidationException::withMessages([
                'material_type' => 'Select Raw Material or Packing Material.',
            ]);
        }

        $supplierId = isset($header['supplier_id']) ? (int) $header['supplier_id'] : 0;
        $supplierName = trim((string) ($header['supplier_name'] ?? ''));
        if ($supplierId > 0) {
            $supplier = Supplier::query()->find($supplierId);
            $supplierName = $supplier?->supplier_name ?? $supplierName;
        }

        if ($supplierName === '') {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier Name is required.',
            ]);
        }

        $invoiceNo = trim((string) ($header['supplier_invoice_number'] ?? ''));
        if ($invoiceNo === '') {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => 'Supplier Invoice No. is required.',
            ]);
        }

        return [
            'purchase_date' => $header['purchase_date'] ?? now('Asia/Kolkata')->toDateString(),
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $supplierName,
            'supplier_invoice_number' => $invoiceNo,
            'supplier_invoice_date' => $header['supplier_invoice_date'] ?? null,
            'material_type' => $type,
            'remarks' => $header['remarks'] ?? null,
            'invoice_path' => $header['invoice_path'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerMeta(
        Purchase $purchase,
        PurchaseItem $item,
        float $oldAvg,
        float $newAvg,
        float $purchaseRate,
        float $inwardValue,
    ): array {
        $supplier = $purchase->displaySupplierName();

        return [
            'transaction_date' => $purchase->purchase_date,
            'transaction_type' => StockTransactionType::Purchase,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'reference_number' => $purchase->purchase_number,
            'supplier_invoice_number' => $purchase->supplier_invoice_number,
            'batch_number' => $item->batch_lot_no,
            'old_average_rate' => $oldAvg,
            'new_average_rate' => $newAvg,
            'inward_value' => $inwardValue,
            'remarks' => trim(implode(' | ', array_filter([
                'Purchase '.$purchase->purchase_number,
                $supplier !== '—' ? 'Supplier: '.$supplier : null,
                $purchase->supplier_invoice_number ? 'Invoice: '.$purchase->supplier_invoice_number : null,
                'Qty: '.$item->quantity,
                'Purchase Rate: '.$purchaseRate.' (ex GST)',
                'Value: '.$inwardValue,
            ]))),
        ];
    }

    private function assertHasItems(Purchase $purchase): void
    {
        if ($purchase->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'At least one material line is required.',
            ]);
        }
    }

    private function assertCanReverseStock(Purchase $purchase): void
    {
        if ($purchase->hasSubsequentStockTransactions()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot edit or cancel because subsequent stock transactions exist.',
            ]);
        }
    }

    private function assertCanCreate(User $user): void
    {
        if (! $user->canCreatePurchase()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to create or confirm purchases.',
            ]);
        }
    }

    private function assertCanUpdate(User $user): void
    {
        if (! $user->canUpdatePurchase()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to edit purchases.',
            ]);
        }
    }
}
