<?php

namespace App\Services\Inventory;

use App\Enums\RawMaterialInwardStatus;
use App\Enums\StockTransactionType;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\PackagingMaterialInwardItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PackagingMaterialInwardService
{
    public function __construct(
        private readonly InventoryService $inventoryService = new InventoryService,
        private readonly StockLedgerService $ledgerService = new StockLedgerService,
        private readonly MaterialInwardCosting $costing = new MaterialInwardCosting,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function createDraft(array $header, array $items, User $user): PackagingMaterialInward
    {
        return DB::transaction(function () use ($header, $items, $user) {
            $inward = PackagingMaterialInward::query()->create([
                ...$this->normalizeHeader($header),
                'inward_number' => $this->nextInwardNumber(),
                'status' => RawMaterialInwardStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->syncItems($inward, $items);
            $this->recalculateHeaderTotals($inward);

            return $inward->fresh(['items', 'supplier', 'createdBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(PackagingMaterialInward $inward, array $header, array $items, User $user): PackagingMaterialInward
    {
        if (! $inward->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only draft inwards can be edited.']);
        }

        return DB::transaction(function () use ($inward, $header, $items) {
            $locked = PackagingMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only draft inwards can be edited.']);
            }

            $locked->fill($this->normalizeHeader($header));
            $locked->save();
            $locked->items()->delete();
            $this->syncItems($locked, $items);
            $this->recalculateHeaderTotals($locked);

            return $locked->fresh(['items', 'supplier', 'createdBy']);
        });
    }

    public function submitForApproval(PackagingMaterialInward $inward, User $user): PackagingMaterialInward
    {
        throw ValidationException::withMessages([
            'status' => 'Approval is not required. Create posts stock immediately.',
        ]);
    }

    public function approve(PackagingMaterialInward $inward, User $user): PackagingMaterialInward
    {
        throw ValidationException::withMessages([
            'status' => 'Approval is not required. Create posts stock immediately.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function createAndPost(array $header, array $items, User $user): PackagingMaterialInward
    {
        if (! $user->canPostRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to post packaging material inwards.',
            ]);
        }

        return DB::transaction(function () use ($header, $items, $user) {
            $inward = PackagingMaterialInward::query()->create([
                ...$this->normalizeHeader($header),
                'inward_number' => $this->nextInwardNumber(),
                'status' => RawMaterialInwardStatus::Draft,
                'created_by' => $user->id,
            ]);

            $this->syncItems($inward, $items);
            $this->recalculateHeaderTotals($inward);

            return $this->postInward($inward->fresh(['items']), $user);
        });
    }

    public function post(PackagingMaterialInward $inward, User $user): PackagingMaterialInward
    {
        if (! $user->canPostRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to post packaging material inwards.',
            ]);
        }

        return DB::transaction(function () use ($inward, $user) {
            $locked = PackagingMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();

            return $this->postInward($locked, $user);
        });
    }

    private function postInward(PackagingMaterialInward $locked, User $user): PackagingMaterialInward
    {
        if ($locked->status === RawMaterialInwardStatus::Posted
            || $locked->status === RawMaterialInwardStatus::Returned) {
            throw ValidationException::withMessages(['status' => 'This inward has already been posted.']);
        }

        if (! $locked->status->canPost()) {
            throw ValidationException::withMessages(['status' => 'Only draft inwards can be posted.']);
        }

        if ($locked->status === RawMaterialInwardStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Cancelled inwards cannot be posted.']);
        }

        $this->assertHasItems($locked);

        foreach ($locked->items()->get() as $item) {
            $this->postItem($locked, $item, $user);
        }

        $locked->status = RawMaterialInwardStatus::Posted;
        $locked->posted_at = now();
        $locked->approved_by = null;
        $locked->approved_at = null;
        $locked->save();

        return $locked->fresh(['items', 'supplier', 'createdBy']);
    }

    public function cancel(PackagingMaterialInward $inward, User $user, ?string $reason = null): PackagingMaterialInward
    {
        if (! $user->canCancelRawMaterialInward()) {
            throw ValidationException::withMessages(['authorization' => 'You are not authorized to cancel inwards.']);
        }

        return DB::transaction(function () use ($inward, $user, $reason) {
            $locked = PackagingMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isImmutable() || $locked->status === RawMaterialInwardStatus::Posted) {
                throw ValidationException::withMessages(['status' => 'Posted or returned inwards cannot be cancelled.']);
            }

            $locked->status = RawMaterialInwardStatus::Cancelled;
            $locked->cancelled_by = $user->id;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function calculateItemAmounts(array $item, ?PackagingMaterial $material = null): array
    {
        return $this->costing->calculateItemAmounts($item);
    }

    public function calculateWeightedAverageRate(
        float $existingStock,
        float $existingAverageRate,
        float $acceptedQuantity,
        float $effectiveUnitRate,
    ): float {
        return $this->costing->calculateWeightedAverageRate(
            $existingStock,
            $existingAverageRate,
            $acceptedQuantity,
            $effectiveUnitRate,
        );
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $header): array
    {
        $supplierId = $header['supplier_id'] ?? null;
        $supplierName = trim((string) ($header['supplier_name'] ?? ''));

        if ($supplierId) {
            $supplier = Supplier::query()->find($supplierId);
            $supplierName = $supplier?->supplier_name ?? $supplierName;
        }

        if ($supplierName === '' && ! $supplierId) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier is required.']);
        }

        $invoiceNumber = trim((string) ($header['supplier_invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            throw ValidationException::withMessages(['supplier_invoice_number' => 'Supplier invoice number is required.']);
        }

        return [
            'inward_date' => $header['inward_date'] ?? now('Asia/Kolkata')->toDateString(),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName !== '' ? $supplierName : null,
            'supplier_invoice_number' => $invoiceNumber,
            'supplier_invoice_date' => $header['supplier_invoice_date'] ?? null,
            'remarks' => $header['remarks'] ?? null,
            'attachment_path' => $header['attachment_path'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(PackagingMaterialInward $inward, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'At least one packaging material item is required.']);
        }

        foreach ($items as $index => $rawItem) {
            $material = PackagingMaterial::query()->find((int) ($rawItem['packaging_material_id'] ?? 0));
            if (! $material || ! $material->status) {
                throw ValidationException::withMessages([
                    'items' => 'Invalid packaging material selected on line '.($index + 1).'.',
                ]);
            }

            $calculated = $this->calculateItemAmounts($rawItem, $material);

            PackagingMaterialInwardItem::query()->create([
                'packaging_material_inward_id' => $inward->id,
                'packaging_material_id' => $material->id,
                'material_code' => $material->packaging_code,
                'material_name' => $material->packaging_name,
                'received_quantity' => $calculated['received_quantity'],
                'accepted_quantity' => $calculated['accepted_quantity'],
                'rejected_quantity' => 0,
                'free_quantity' => 0,
                'unit' => $material->unit,
                'basic_rate' => $calculated['basic_rate'],
                'discount_percentage' => 0,
                'discount_amount' => $calculated['discount_amount'],
                'freight_amount' => $calculated['freight_amount'],
                'loading_unloading_amount' => 0,
                'other_charges' => $calculated['other_charges'],
                'taxable_amount' => $calculated['taxable_amount'],
                'gst_percentage' => $calculated['gst_percentage'],
                'cgst_amount' => $calculated['cgst_amount'],
                'sgst_amount' => $calculated['sgst_amount'],
                'igst_amount' => $calculated['igst_amount'],
                'total_amount' => $calculated['total_amount'],
                'landed_cost' => $calculated['landed_cost'],
                'effective_unit_rate' => $calculated['effective_unit_rate'],
                'remarks' => $calculated['remarks'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    private function recalculateHeaderTotals(PackagingMaterialInward $inward): void
    {
        $items = $inward->items()->get();
        $inward->total_items = $items->count();
        $inward->total_basic_value = round($items->sum(fn ($i) => (float) $i->accepted_quantity * (float) $i->basic_rate), 2);
        $inward->total_discount = round($items->sum('discount_amount'), 2);
        $inward->total_freight = round($items->sum('freight_amount'), 2);
        $inward->total_other_charges = round($items->sum('other_charges'), 2);
        $inward->total_taxable_value = round($items->sum('taxable_amount'), 2);
        $inward->total_gst = round($items->sum(fn ($i) => (float) $i->cgst_amount + (float) $i->sgst_amount + (float) $i->igst_amount), 2);
        // Header Total Value is stock meaning: sum of Effective Inventory Values (landed_cost).
        $inward->grand_total = round($items->sum('landed_cost'), 2);
        $inward->total_accepted_qty = round($items->sum('accepted_quantity'), 3);
        $inward->total_rejected_qty = 0;
        $inward->save();
    }

    private function postItem(PackagingMaterialInward $inward, PackagingMaterialInwardItem $item, User $user): void
    {
        $material = $this->inventoryService->lockPackagingMaterial((int) $item->packaging_material_id);

        $calculated = $this->costing->calculateItemAmounts([
            'inward_quantity' => (float) $item->accepted_quantity,
            'basic_rate' => (float) $item->basic_rate,
            'discount_amount' => (float) $item->discount_amount,
            'freight_amount' => (float) $item->freight_amount,
            'other_charges' => (float) $item->other_charges,
            'gst_percentage' => (float) $item->gst_percentage,
        ]);

        $accepted = (float) $calculated['accepted_quantity'];
        $effectiveRate = (float) $calculated['effective_unit_rate'];
        $oldStock = (float) $material->current_stock;
        $oldAvg = (float) $material->average_rate;
        $stockAfter = round($oldStock + $accepted, 3);
        $newAvg = $accepted > 0
            ? $this->calculateWeightedAverageRate($oldStock, $oldAvg, $accepted, $effectiveRate)
            : $oldAvg;

        $item->fill([
            'taxable_amount' => $calculated['taxable_amount'],
            'cgst_amount' => $calculated['cgst_amount'],
            'sgst_amount' => $calculated['sgst_amount'],
            'igst_amount' => $calculated['igst_amount'],
            'landed_cost' => $calculated['landed_cost'],
            'effective_unit_rate' => $effectiveRate,
            'total_amount' => $calculated['total_amount'],
            'stock_before' => $oldStock,
            'stock_after' => $stockAfter,
            'old_average_rate' => $oldAvg,
            'new_average_rate' => $newAvg,
        ]);
        $item->save();

        if ($accepted <= 0) {
            return;
        }

        $this->ledgerService->postPackagingMaterialMovement(
            $material,
            $accepted,
            0,
            $effectiveRate,
            [
                'transaction_date' => $inward->inward_date,
                'transaction_type' => StockTransactionType::PackagingMaterialInward,
                'reference_type' => PackagingMaterialInward::class,
                'reference_id' => $inward->id,
                'reference_number' => $inward->inward_number,
                'supplier_invoice_number' => $inward->supplier_invoice_number,
                'old_average_rate' => $oldAvg,
                'new_average_rate' => $newAvg,
                'remarks' => trim(implode(' | ', array_filter([
                    $inward->displaySupplierName() !== '—' ? 'Supplier: '.$inward->displaySupplierName() : null,
                    $inward->supplier_invoice_number ? 'Invoice: '.$inward->supplier_invoice_number : null,
                    'Old Avg: '.$oldAvg,
                    'Eff Rate: '.$effectiveRate,
                    'New Avg: '.$newAvg,
                ]))),
            ],
            $user,
        );

        $material->refresh();
        $material->average_rate = $newAvg;
        $material->purchase_rate = $effectiveRate > 0 ? $effectiveRate : $material->purchase_rate;
        $material->current_stock_value = round((float) $material->current_stock * $newAvg, 2);
        $material->save();
    }

    private function assertHasItems(PackagingMaterialInward $inward): void
    {
        if ($inward->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'At least one packaging material item is required.',
            ]);
        }
    }

    private function nextInwardNumber(): string
    {
        $prefix = config('inventory.packaging_material_inward_prefix', 'PMI');
        $last = PackagingMaterialInward::query()
            ->where('inward_number', 'like', $prefix.'%')
            ->orderByDesc('inward_number')
            ->value('inward_number');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
