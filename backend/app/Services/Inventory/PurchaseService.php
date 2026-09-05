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
        private readonly PurchaseFreightAllocator $freightAllocator = new PurchaseFreightAllocator,
        private readonly TransportFreightLedgerService $transportLedger = new TransportFreightLedgerService,
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
                $this->reverseConfirmedPostings($locked, $user, 'Purchase cancel — '.$locked->purchase_number);
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
        $this->reverseConfirmedPostings($locked, $user, 'Purchase edit reversal — '.$locked->purchase_number);

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
        $this->applyFreightAllocation($locked);
        $items = $locked->items()->get();

        foreach ($items as $item) {
            $this->postItem($locked, $item, $user);
        }

        $this->transportLedger->postCharge($locked, $user);

        $locked->status = PurchaseStatus::Confirmed;
        $locked->confirmed_by = $user->id;
        $locked->confirmed_at = now();
        $locked->save();

        return $locked->fresh(['items', 'supplier', 'createdBy']);
    }

    private function postItem(Purchase $purchase, PurchaseItem $item, User $user): void
    {
        $qty = (float) $item->quantity;
        $landedRate = (float) $item->effective_unit_rate;
        if ($landedRate <= 0) {
            $landedRate = (float) $item->purchase_rate;
        }

        if ($purchase->material_type === PurchaseMaterialType::RawMaterial) {
            $material = $this->inventoryService->lockRawMaterial((int) $item->raw_material_id);
            $this->applyInwardToMaterial($purchase, $item, $user, $material, $qty, $landedRate);
        } else {
            $material = $this->inventoryService->lockPackagingMaterial((int) $item->packaging_material_id);
            $this->applyInwardToMaterial($purchase, $item, $user, $material, $qty, $landedRate);
        }
    }

    /**
     * @param  RawMaterial|PackagingMaterial  $material
     */
    private function applyInwardToMaterial(
        Purchase $purchase,
        PurchaseItem $item,
        User $user,
        RawMaterial|PackagingMaterial $material,
        float $qty,
        float $landedRate,
    ): void {
        $oldStock = (float) $material->current_stock;
        $oldAvg = (float) $material->average_rate;
        $stockAfter = round($oldStock + $qty, 3);
        $newAvg = $qty > 0
            ? $this->weightedAverage->newAverageRate($oldStock, $oldAvg, $qty, $landedRate)
            : $oldAvg;
        $stockValue = $this->weightedAverage->stockValue($stockAfter, $newAvg);
        $inwardValue = $this->weightedAverage->stockValue($qty, $landedRate);
        $purchaseRate = (float) $item->purchase_rate;

        $item->fill([
            'stock_before' => $oldStock,
            'stock_after' => $stockAfter,
            'old_average_rate' => $oldAvg,
            'new_average_rate' => $newAvg,
        ]);
        $item->save();

        if ($qty <= 0) {
            return;
        }

        $meta = $this->ledgerMeta($purchase, $item, $oldAvg, $newAvg, $purchaseRate, $landedRate, $inwardValue);

        if ($material instanceof RawMaterial) {
            $this->ledgerService->postRawMaterialMovement($material, $qty, 0, $landedRate, $meta, $user);
        } else {
            $this->ledgerService->postPackagingMaterialMovement($material, $qty, 0, $landedRate, $meta, $user);
        }

        $material->refresh();
        $material->average_rate = $newAvg;
        $material->purchase_rate = $purchaseRate > 0 ? $purchaseRate : $material->purchase_rate;
        $material->current_stock_value = $stockValue;
        $material->save();
    }

    private function reverseConfirmedPostings(Purchase $locked, User $user, string $remarks): void
    {
        $this->reverseConfirmedStock($locked, $user, $remarks);
        $this->transportLedger->reverse($locked, $user, $remarks);
    }

    private function reverseConfirmedStock(Purchase $locked, User $user, string $remarks): void
    {
        $items = $locked->items()->orderByDesc('sort_order')->get();

        foreach ($items as $item) {
            $qty = (float) $item->quantity;
            if ($qty <= 0) {
                continue;
            }

            $landedRate = (float) $item->effective_unit_rate;
            if ($landedRate <= 0) {
                $landedRate = (float) $item->purchase_rate;
            }
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
                $this->ledgerService->postRawMaterialMovement($material, 0, $qty, $landedRate, $meta, $user);
            } else {
                $material = $this->inventoryService->lockPackagingMaterial((int) $item->packaging_material_id);
                if ((float) $material->current_stock + 0.0001 < $qty) {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot reverse this purchase because subsequent stock transactions exist.',
                    ]);
                }
                $meta['old_average_rate'] = (float) $material->average_rate;
                $meta['new_average_rate'] = $restoreAvg ?? (float) $material->average_rate;
                $this->ledgerService->postPackagingMaterialMovement($material, 0, $qty, $landedRate, $meta, $user);
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

            $qty = (float) $calculated['accepted_quantity'];
            $taxable = (float) $calculated['taxable_amount'];

            PurchaseItem::query()->create([
                'purchase_id' => $purchase->id,
                'raw_material_id' => $rawId,
                'packaging_material_id' => $packId,
                'unit' => $unit,
                'quantity' => $qty,
                'purchase_rate' => $calculated['basic_rate'],
                'taxable_amount' => $taxable,
                'gst_percentage' => $calculated['gst_percentage'],
                'gst_amount' => $calculated['igst_amount'],
                'total_amount' => $calculated['total_amount'],
                'allocated_transport_cost' => 0,
                'landed_cost' => $taxable,
                'effective_unit_rate' => $qty > 0 ? round($taxable / $qty, 4) : 0,
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

        $this->applyFreightAllocation($purchase);
    }

    private function applyFreightAllocation(Purchase $purchase): void
    {
        $items = $purchase->items()->orderBy('sort_order')->get();
        $freight = round((float) $purchase->transport_cost, 2);
        $taxables = $items->map(fn (PurchaseItem $item): float => (float) $item->taxable_amount)->all();
        $allocated = $this->freightAllocator->allocate($freight, array_values($taxables));

        foreach ($items->values() as $index => $item) {
            $qty = (float) $item->quantity;
            $taxable = (float) $item->taxable_amount;
            $alloc = (float) ($allocated[$index] ?? 0);
            $item->allocated_transport_cost = $alloc;
            $item->landed_cost = $this->freightAllocator->landedCost($taxable, $alloc);
            $item->effective_unit_rate = $this->freightAllocator->effectiveLandedRate($qty, $taxable, $alloc);
            $item->save();
        }

        $purchase->total_landed_cost = round((float) $purchase->total_taxable_amount + $freight, 2);
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

        $transportCost = round((float) ($header['transport_cost'] ?? 0), 2);
        if ($transportCost < 0 || ! is_finite($transportCost)) {
            throw ValidationException::withMessages([
                'transport_cost' => 'Transport/Freight Cost cannot be negative.',
            ]);
        }

        $transporterName = trim((string) ($header['transporter_name'] ?? ''));
        $transportLr = trim((string) ($header['transport_invoice_lr_no'] ?? ''));
        $transportRemark = trim((string) ($header['transport_remark'] ?? ''));

        return [
            'purchase_date' => $header['purchase_date'] ?? now('Asia/Kolkata')->toDateString(),
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $supplierName,
            'supplier_invoice_number' => $invoiceNo,
            'supplier_invoice_date' => $header['supplier_invoice_date'] ?? null,
            'material_type' => $type,
            'remarks' => $header['remarks'] ?? null,
            'invoice_path' => $header['invoice_path'] ?? null,
            'transport_cost' => $transportCost,
            'transporter_name' => $transporterName !== '' ? $transporterName : null,
            'transport_invoice_lr_no' => $transportLr !== '' ? $transportLr : null,
            'transport_remark' => $transportRemark !== '' ? $transportRemark : null,
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
        float $landedRate,
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
                ((float) $item->allocated_transport_cost) > 0
                    ? 'Allocated Freight: '.$item->allocated_transport_cost
                    : null,
                'Landed Rate: '.$landedRate,
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
