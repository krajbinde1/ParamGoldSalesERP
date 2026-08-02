<?php

namespace App\Services\Inventory;

use App\Enums\RawMaterialBatchStatus;
use App\Enums\RawMaterialInwardStatus;
use App\Enums\StockTransactionType;
use App\Models\RawMaterial;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialInward;
use App\Models\RawMaterialInwardItem;
use App\Models\RawMaterialInwardReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RawMaterialInwardService
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
    public function createDraft(array $header, array $items, User $user): RawMaterialInward
    {
        return DB::transaction(function () use ($header, $items, $user) {
            $inward = RawMaterialInward::query()->create([
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
    public function updateDraft(RawMaterialInward $inward, array $header, array $items, User $user): RawMaterialInward
    {
        $this->assertEditable($inward);

        return DB::transaction(function () use ($inward, $header, $items) {
            $locked = RawMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $locked->fill($this->normalizeHeader($header));
            $locked->save();

            $locked->items()->delete();
            $this->syncItems($locked, $items);
            $this->recalculateHeaderTotals($locked);

            return $locked->fresh(['items', 'supplier', 'createdBy']);
        });
    }

    /**
     * Update a draft normally, or reverse-then-repost a posted inward with no dependents.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function update(RawMaterialInward $inward, array $header, array $items, User $user): RawMaterialInward
    {
        if (! $user->canUpdateRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to edit raw material inwards.',
            ]);
        }

        if ($inward->isEditable()) {
            return $this->updateDraft($inward, $header, $items, $user);
        }

        return $this->updatePostedSafely($inward, $header, $items, $user);
    }

    /**
     * Authorized edit of a posted inward: reverse stock via ledger, update document, repost — one transaction.
     *
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function updatePostedSafely(RawMaterialInward $inward, array $header, array $items, User $user): RawMaterialInward
    {
        if (! $user->canUpdateRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to edit raw material inwards.',
            ]);
        }

        return DB::transaction(function () use ($inward, $header, $items, $user) {
            $locked = RawMaterialInward::query()
                ->with(['items', 'batches'])
                ->whereKey($inward->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanSafelyEditPosted($locked);
            $this->reversePostedStock($locked, $user);

            $locked->refresh();
            $locked->status = RawMaterialInwardStatus::Draft;
            $locked->posted_at = null;
            $locked->fill($this->normalizeHeader($header));
            $locked->save();

            $locked->items()->delete();
            $this->syncItems($locked, $items);
            $this->recalculateHeaderTotals($locked);

            return $this->postInward($locked->fresh(['items']), $user);
        });
    }

    public function submitForApproval(RawMaterialInward $inward, User $user): RawMaterialInward
    {
        throw ValidationException::withMessages([
            'status' => 'Approval is not required. Create posts stock immediately.',
        ]);
    }

    public function approve(RawMaterialInward $inward, User $user): RawMaterialInward
    {
        throw ValidationException::withMessages([
            'status' => 'Approval is not required. Create posts stock immediately.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $items
     */
    public function createAndPost(array $header, array $items, User $user): RawMaterialInward
    {
        if (! $user->canPostRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to post raw material inwards.',
            ]);
        }

        return DB::transaction(function () use ($header, $items, $user) {
            $inward = RawMaterialInward::query()->create([
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

    public function post(RawMaterialInward $inward, User $user): RawMaterialInward
    {
        if (! $user->canPostRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to post raw material inwards.',
            ]);
        }

        return DB::transaction(function () use ($inward, $user) {
            $locked = RawMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();

            return $this->postInward($locked, $user);
        });
    }

    private function postInward(RawMaterialInward $locked, User $user): RawMaterialInward
    {
        if ($locked->status === RawMaterialInwardStatus::Posted
            || $locked->status === RawMaterialInwardStatus::Returned) {
            throw ValidationException::withMessages([
                'status' => 'This inward has already been posted.',
            ]);
        }

        if (! $locked->status->canPost()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft inwards can be posted.',
            ]);
        }

        if ($locked->status === RawMaterialInwardStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Cancelled inwards cannot be posted.',
            ]);
        }

        $this->assertHasItems($locked);
        $items = $locked->items()->get();

        foreach ($items as $item) {
            $this->postItem($locked, $item, $user);
        }

        $locked->status = RawMaterialInwardStatus::Posted;
        $locked->posted_at = now();
        $locked->approved_by = null;
        $locked->approved_at = null;
        $locked->save();

        return $locked->fresh(['items', 'supplier', 'createdBy', 'batches']);
    }

    public function cancel(RawMaterialInward $inward, User $user, ?string $reason = null): RawMaterialInward
    {
        if (! $user->canCancelRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to cancel inwards.',
            ]);
        }

        return DB::transaction(function () use ($inward, $user, $reason) {
            $locked = RawMaterialInward::query()->whereKey($inward->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isImmutable() || $locked->status === RawMaterialInwardStatus::Posted) {
                throw ValidationException::withMessages([
                    'status' => 'Posted or returned inwards cannot be cancelled.',
                ]);
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
     * @param  array<string, mixed>  $data
     */
    public function createAndPostReturn(array $data, User $user): RawMaterialInwardReturn
    {
        if (! $user->canPostRawMaterialInward()) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not authorized to post inward returns.',
            ]);
        }

        return DB::transaction(function () use ($data, $user) {
            $inward = RawMaterialInward::query()
                ->whereKey((int) $data['raw_material_inward_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($inward->status !== RawMaterialInwardStatus::Posted
                && $inward->status !== RawMaterialInwardStatus::Returned) {
                throw ValidationException::withMessages([
                    'raw_material_inward_id' => 'Only posted inwards can be returned.',
                ]);
            }

            $returnQty = round((float) $data['return_quantity'], 3);
            if ($returnQty <= 0) {
                throw ValidationException::withMessages([
                    'return_quantity' => 'Return quantity must be greater than zero.',
                ]);
            }

            $material = $this->inventoryService->lockRawMaterial((int) $data['raw_material_id']);
            $batch = null;

            if (! empty($data['raw_material_batch_id'])) {
                /** @var RawMaterialBatch $batch */
                $batch = RawMaterialBatch::query()
                    ->whereKey((int) $data['raw_material_batch_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $batch->raw_material_id !== (int) $material->id) {
                    throw ValidationException::withMessages([
                        'raw_material_batch_id' => 'Batch does not belong to the selected material.',
                    ]);
                }

                if ((float) $batch->available_quantity + 0.0001 < $returnQty) {
                    throw ValidationException::withMessages([
                        'return_quantity' => "Return quantity exceeds batch available stock ({$batch->available_quantity}).",
                    ]);
                }
            }

            if ((float) $material->current_stock + 0.0001 < $returnQty) {
                throw ValidationException::withMessages([
                    'return_quantity' => "Return quantity exceeds available stock ({$material->current_stock}).",
                ]);
            }

            $rate = (float) ($data['return_rate'] ?? $batch?->effective_unit_rate ?? $material->average_rate);

            $return = RawMaterialInwardReturn::query()->create([
                'return_number' => $this->nextReturnNumber(),
                'raw_material_inward_id' => $inward->id,
                'raw_material_inward_item_id' => $data['raw_material_inward_item_id'] ?? null,
                'raw_material_id' => $material->id,
                'raw_material_batch_id' => $batch?->id,
                'return_date' => $data['return_date'] ?? now('Asia/Kolkata')->toDateString(),
                'return_quantity' => $returnQty,
                'reason' => $data['reason'],
                'supplier_credit_note_number' => $data['supplier_credit_note_number'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'posted',
                'return_rate' => $rate,
                'return_value' => round($returnQty * $rate, 2),
                'created_by' => $user->id,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'posted_at' => now(),
            ]);

            $oldStock = (float) $material->current_stock;
            $oldAvg = (float) $material->average_rate;
            $newStock = round($oldStock - $returnQty, 3);

            // Reduce stock value by return qty × current average (keeps average rate unless stock hits zero).
            $this->ledgerService->postRawMaterialMovement(
                $material,
                0,
                $returnQty,
                $rate,
                [
                    'transaction_date' => $return->return_date,
                    'transaction_type' => StockTransactionType::PurchaseReturn,
                    'reference_type' => RawMaterialInwardReturn::class,
                    'reference_id' => $return->id,
                    'reference_number' => $return->return_number,
                    'batch_number' => $batch?->internal_batch_number,
                    'remarks' => trim(implode(' | ', array_filter([
                        'Purchase return for '.$inward->inward_number,
                        $return->reason,
                        $return->supplier_credit_note_number
                            ? 'CN: '.$return->supplier_credit_note_number
                            : null,
                    ]))),
                ],
                $user,
            );

            $material->refresh();
            if ($newStock <= 0) {
                $material->average_rate = 0;
                $material->current_stock_value = 0;
            } else {
                $material->average_rate = $oldAvg;
                $material->current_stock_value = round($newStock * $oldAvg, 2);
            }
            $material->save();

            if ($batch) {
                $batch->available_quantity = round((float) $batch->available_quantity - $returnQty, 3);
                $batch->returned_quantity = round((float) $batch->returned_quantity + $returnQty, 3);
                $batch->refreshStatus();
            }

            $inward->status = RawMaterialInwardStatus::Returned;
            $inward->save();

            return $return->fresh(['inward', 'rawMaterial', 'batch']);
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function calculateItemAmounts(array $item, ?RawMaterial $material = null): array
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
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier is required.',
            ]);
        }

        $invoiceNumber = trim((string) ($header['supplier_invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            throw ValidationException::withMessages([
                'supplier_invoice_number' => 'Supplier invoice number is required.',
            ]);
        }

        return [
            'inward_date' => $header['inward_date'] ?? now('Asia/Kolkata')->toDateString(),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName !== '' ? $supplierName : null,
            'supplier_invoice_number' => $invoiceNumber,
            'supplier_invoice_date' => $header['supplier_invoice_date'] ?? null,
            'purchase_order_number' => null,
            'vehicle_number' => null,
            'transporter_name' => null,
            'challan_number' => null,
            'received_by' => null,
            'warehouse' => null,
            'remarks' => $header['remarks'] ?? null,
            'attachment_path' => $header['attachment_path'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(RawMaterialInward $inward, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'At least one raw material item is required.',
            ]);
        }

        foreach ($items as $index => $rawItem) {
            $materialId = (int) ($rawItem['raw_material_id'] ?? 0);
            $material = RawMaterial::query()->find($materialId);

            if (! $material) {
                throw ValidationException::withMessages([
                    'items' => 'Invalid raw material selected on line '.($index + 1).'.',
                ]);
            }

            $calculated = $this->calculateItemAmounts($rawItem, $material);
            $internalBatch = $calculated['internal_batch_number'] ?? null;

            if ($material->batch_tracking_enabled && blank($internalBatch)) {
                $internalBatch = $this->nextBatchNumber($material);
            }

            RawMaterialInwardItem::query()->create([
                'raw_material_inward_id' => $inward->id,
                'raw_material_id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'supplier_batch_number' => null,
                'internal_batch_number' => $internalBatch,
                'manufacturing_date' => null,
                'expiry_date' => null,
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

    private function recalculateHeaderTotals(RawMaterialInward $inward): void
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

    private function postItem(RawMaterialInward $inward, RawMaterialInwardItem $item, User $user): void
    {
        $material = $this->inventoryService->lockRawMaterial((int) $item->raw_material_id);

        $calculated = $this->costing->calculateItemAmounts([
            'inward_quantity' => (float) $item->accepted_quantity,
            'basic_rate' => (float) $item->basic_rate,
            'discount_amount' => (float) $item->discount_amount,
            'freight_amount' => (float) $item->freight_amount,
            'other_charges' => (float) $item->other_charges,
            'gst_percentage' => (float) $item->gst_percentage,
        ]);

        $accepted = (float) $calculated['accepted_quantity'];
        $rejected = (float) $item->rejected_quantity;
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

        if ($accepted > 0) {
            $this->ledgerService->postRawMaterialMovement(
                $material,
                $accepted,
                0,
                $effectiveRate,
                [
                    'transaction_date' => $inward->inward_date,
                    'transaction_type' => StockTransactionType::RawMaterialInward,
                    'reference_type' => RawMaterialInward::class,
                    'reference_id' => $inward->id,
                    'reference_number' => $inward->inward_number,
                    'supplier_invoice_number' => $inward->supplier_invoice_number,
                    'batch_number' => $item->internal_batch_number,
                    'old_average_rate' => $oldAvg,
                    'new_average_rate' => $newAvg,
                    'remarks' => trim(implode(' | ', array_filter([
                        $inward->supplier_invoice_number
                            ? 'Invoice: '.$inward->supplier_invoice_number
                            : null,
                        $item->expiry_date
                            ? 'Expiry: '.$item->expiry_date->toDateString()
                            : null,
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

        // Rejected quantity never increases available stock; optional rejected batch for audit.
        if ($material->batch_tracking_enabled && $accepted > 0) {
            $batch = RawMaterialBatch::query()->create([
                'raw_material_id' => $material->id,
                'internal_batch_number' => $item->internal_batch_number ?? $this->nextBatchNumber($material),
                'supplier_batch_number' => $item->supplier_batch_number,
                'inward_id' => $inward->id,
                'inward_item_id' => $item->id,
                'manufacturing_date' => $item->manufacturing_date,
                'expiry_date' => $item->expiry_date,
                'received_quantity' => $item->received_quantity,
                'accepted_quantity' => $accepted,
                'available_quantity' => $accepted,
                'effective_unit_rate' => $effectiveRate,
                'status' => RawMaterialBatchStatus::Available,
            ]);
            $batch->refreshStatus();
        } elseif ($material->batch_tracking_enabled && $rejected > 0 && $accepted <= 0) {
            RawMaterialBatch::query()->create([
                'raw_material_id' => $material->id,
                'internal_batch_number' => $item->internal_batch_number ?? $this->nextBatchNumber($material),
                'supplier_batch_number' => $item->supplier_batch_number,
                'inward_id' => $inward->id,
                'inward_item_id' => $item->id,
                'manufacturing_date' => $item->manufacturing_date,
                'expiry_date' => $item->expiry_date,
                'received_quantity' => $item->received_quantity,
                'accepted_quantity' => 0,
                'available_quantity' => 0,
                'effective_unit_rate' => $effectiveRate,
                'status' => RawMaterialBatchStatus::Rejected,
            ]);
        }
    }

    private function assertEditable(RawMaterialInward $inward): void
    {
        if (! $inward->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft inwards can be edited.',
            ]);
        }
    }

    private function assertCanSafelyEditPosted(RawMaterialInward $inward): void
    {
        if ($inward->status !== RawMaterialInwardStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => 'Only posted inwards without dependents can use safe reverse-repost edit.',
            ]);
        }

        if ($inward->hasSubsequentStockTransactions()) {
            throw ValidationException::withMessages([
                'status' => 'Cannot edit because subsequent stock transactions exist.',
            ]);
        }
    }

    /**
     * Reverse posted inward stock impact via BatchReversal ledger entries (never silently mutate ledgers).
     */
    private function reversePostedStock(RawMaterialInward $locked, User $user): void
    {
        $items = $locked->items()->get();

        foreach ($items as $item) {
            $accepted = (float) $item->accepted_quantity;
            if ($accepted <= 0) {
                continue;
            }

            $material = $this->inventoryService->lockRawMaterial((int) $item->raw_material_id);
            $effectiveRate = (float) $item->effective_unit_rate;
            $oldAvg = $item->old_average_rate !== null
                ? (float) $item->old_average_rate
                : (float) $material->average_rate;

            if ((float) $material->current_stock + 0.0001 < $accepted) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot edit because subsequent stock transactions exist.',
                ]);
            }

            $this->ledgerService->postRawMaterialMovement(
                $material,
                0,
                $accepted,
                $effectiveRate,
                [
                    'transaction_date' => now('Asia/Kolkata')->toDateString(),
                    'transaction_type' => StockTransactionType::BatchReversal,
                    'reference_type' => RawMaterialInward::class,
                    'reference_id' => $locked->id,
                    'reference_number' => $locked->inward_number,
                    'supplier_invoice_number' => $locked->supplier_invoice_number,
                    'batch_number' => $item->internal_batch_number,
                    'old_average_rate' => (float) $material->average_rate,
                    'new_average_rate' => $oldAvg,
                    'remarks' => 'Inward edit reversal — '.$locked->inward_number,
                ],
                $user,
            );

            $material->refresh();
            $material->average_rate = $oldAvg;
            $material->current_stock_value = round((float) $material->current_stock * $oldAvg, 2);
            $material->save();
        }

        // Remove unused batches created by this inward so repost can recreate them cleanly.
        RawMaterialBatch::query()->where('inward_id', $locked->id)->delete();
    }

    private function assertHasItems(RawMaterialInward $inward): void
    {
        if ($inward->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'At least one raw material item is required.',
            ]);
        }
    }

    private function nextInwardNumber(): string
    {
        $prefix = config('inventory.raw_material_inward_prefix', 'RMI');
        $last = RawMaterialInward::query()
            ->where('inward_number', 'like', $prefix.'%')
            ->orderByDesc('inward_number')
            ->value('inward_number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function nextReturnNumber(): string
    {
        $prefix = config('inventory.raw_material_inward_return_prefix', config('inventory.inward_return_prefix', 'IRR'));
        $last = RawMaterialInwardReturn::query()
            ->where('return_number', 'like', $prefix.'%')
            ->orderByDesc('return_number')
            ->value('return_number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function nextBatchNumber(RawMaterial $material): string
    {
        $prefix = config('inventory.raw_material_batch_prefix', 'RMB');
        $last = RawMaterialBatch::query()
            ->where('internal_batch_number', 'like', $prefix.'%')
            ->orderByDesc('internal_batch_number')
            ->value('internal_batch_number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT).'-'.$material->material_code;
    }
}
