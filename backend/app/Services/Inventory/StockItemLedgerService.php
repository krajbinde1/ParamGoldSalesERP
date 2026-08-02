<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use App\Filament\Resources\ProductionBatches\ProductionBatchResource;
use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Models\SemiFinishedMaterial;
use App\Models\StockAdjustment;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Read-only Tally-style item stock ledger. Does not create stock transactions.
 */
final class StockItemLedgerService
{
    public const CHUNK_SIZE = 250;

    /**
     * @param  array{
     *     item_type?: string|null,
     *     item_id?: int|string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     transaction_type?: string|null,
     *     voucher_number?: string|null,
     *     supplier?: string|null,
     *     production_batch?: string|null,
     *     inward_only?: bool|int|string|null,
     *     outward_only?: bool|int|string|null,
     *     page?: int|string|null,
     *     per_page?: int|string|null
     * }  $filters
     */
    public function build(array $filters, bool $requireItem = true): StockItemLedgerResult
    {
        $itemType = StockItemType::tryFrom((string) ($filters['item_type'] ?? ''));
        $itemId = (int) ($filters['item_id'] ?? 0);

        if ($requireItem && (! $itemType || $itemId <= 0)) {
            throw ValidationException::withMessages([
                'item_id' => 'Select an item to view the stock ledger.',
            ]);
        }

        if (! $itemType || $itemId <= 0) {
            return $this->emptyResult($filters);
        }

        $from = $this->normalizeDate($filters['from'] ?? null) ?? now('Asia/Kolkata')->startOfMonth()->toDateString();
        $to = $this->normalizeDate($filters['to'] ?? null) ?? now('Asia/Kolkata')->toDateString();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));

        $item = $this->resolveItem($itemType, $itemId);
        $opening = $this->computeOpeningBalance($itemType, $itemId, $from);

        $periodQuery = $this->baseItemQuery($itemType, $itemId)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to);

        $this->applyExtraFilters($periodQuery, $filters);

        $totalCount = (clone $periodQuery)->count();
        $offset = ($page - 1) * $perPage;

        $state = [
            'qty' => $opening['qty'],
            'value' => $opening['value'],
            'rate' => $opening['rate'],
            'clamped' => $opening['clamped'],
        ];

        $rows = [];
        $totals = [
            'total_inward_qty' => 0.0,
            'total_inward_value' => 0.0,
            'total_outward_qty' => 0.0,
            'total_outward_value' => 0.0,
        ];
        $index = 0;

        $this->chronologicalChunk($periodQuery, function (StockLedger $ledger) use (
            &$state,
            &$rows,
            &$totals,
            &$index,
            $offset,
            $perPage,
        ): void {
            $applied = $this->applyTransaction($state, $ledger);
            $state = $applied['state'];

            $totals['total_inward_qty'] = round($totals['total_inward_qty'] + $applied['row']['inward_qty'], 3);
            $totals['total_inward_value'] = round($totals['total_inward_value'] + $applied['row']['inward_value'], 2);
            $totals['total_outward_qty'] = round($totals['total_outward_qty'] + $applied['row']['outward_qty'], 3);
            $totals['total_outward_value'] = round($totals['total_outward_value'] + $applied['row']['outward_value'], 2);

            if ($index >= $offset && count($rows) < $perPage) {
                $rows[] = $applied['row'];
            }

            $index++;
        });

        $warning = $state['clamped']
            ? 'Stock quantity was clamped at zero for display where calculated balance went negative.'
            : null;

        $header = [
            'item_type' => $itemType->value,
            'item_type_label' => $itemType->label(),
            'item_id' => $itemId,
            'item_name' => $item['name'],
            'item_code' => $item['code'],
            'category' => $item['category'],
            'unit' => $item['unit'],
            'available_quantity' => $item['available_quantity'],
            'minimum_stock' => $item['minimum_stock'],
            'stock_status' => $item['stock_status'],
            'current_stock_value' => $item['current_stock_value'],
            'available_stock_value' => $item['current_stock_value'],
            'current_average_rate' => $item['current_average_rate'],
            'from' => $from,
            'to' => $to,
            'opening_qty' => $opening['qty'],
            'opening_value' => $opening['value'],
            'opening_rate' => $opening['rate'],
            'closing_qty' => $state['qty'],
            'closing_value' => $state['value'],
            'closing_rate' => $state['rate'],
            'warning' => $warning,
        ];

        return new StockItemLedgerResult(
            header: $header,
            rows: $rows,
            totals: [
                ...$totals,
                'closing_qty' => $state['qty'],
                'closing_rate' => $state['rate'],
                'closing_value' => $state['value'],
            ],
            totalTransactionCount: $totalCount,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * Stream every display row including opening (for export/print).
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamRows(array $filters): \Generator
    {
        $result = $this->build([
            ...$filters,
            'page' => 1,
            'per_page' => 1,
        ], requireItem: true);

        $header = $result->header;

        yield [
            'row_type' => 'opening',
            'date' => Carbon::parse((string) $header['from'])->format('d-m-Y'),
            'particulars' => 'Opening Balance',
            'voucher_type' => '',
            'voucher_no' => '',
            'voucher_url' => null,
            'inward_qty' => null,
            'inward_rate' => null,
            'inward_value' => null,
            'outward_qty' => null,
            'outward_rate' => null,
            'outward_value' => null,
            'closing_qty' => $header['opening_qty'],
            'closing_rate' => $header['opening_rate'],
            'closing_value' => $header['opening_value'],
        ];

        $itemType = StockItemType::from($header['item_type']);
        $itemId = (int) $header['item_id'];
        $from = $header['from'];
        $to = $header['to'];

        $periodQuery = $this->baseItemQuery($itemType, $itemId)
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to);

        $this->applyExtraFilters($periodQuery, $filters);

        $state = [
            'qty' => $header['opening_qty'],
            'value' => $header['opening_value'],
            'rate' => $header['opening_rate'],
            'clamped' => false,
        ];

        foreach ($this->chronologicalCursor($periodQuery) as $ledger) {
            $applied = $this->applyTransaction($state, $ledger);
            $state = $applied['state'];
            yield $applied['row'];
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function buildForExport(array $filters): StockItemLedgerResult
    {
        $summary = $this->build([
            ...$filters,
            'page' => 1,
            'per_page' => 1,
        ], requireItem: true);

        $rows = [];
        foreach ($this->streamRows($filters) as $row) {
            $rows[] = $row;
        }

        return new StockItemLedgerResult(
            header: $summary->header,
            rows: $rows,
            totals: $summary->totals,
            totalTransactionCount: $summary->totalTransactionCount,
            page: 1,
            perPage: max(1, $summary->totalTransactionCount),
        );
    }

    /**
     * @return array{qty: float, value: float, rate: float, clamped: bool}
     */
    public function computeOpeningBalance(StockItemType $itemType, int $itemId, string $fromDate): array
    {
        $query = $this->baseItemQuery($itemType, $itemId)
            ->whereDate('transaction_date', '<', $fromDate);

        $state = [
            'qty' => 0.0,
            'value' => 0.0,
            'rate' => 0.0,
            'clamped' => false,
        ];

        $this->chronologicalChunk($query, function (StockLedger $ledger) use (&$state): void {
            $applied = $this->applyTransaction($state, $ledger);
            $state = $applied['state'];
        });

        return $state;
    }

    /**
     * @param  array{qty: float, value: float, rate: float, clamped: bool}  $state
     * @return array{state: array{qty: float, value: float, rate: float, clamped: bool}, row: array<string, mixed>}
     */
    public function applyTransaction(array $state, StockLedger $ledger): array
    {
        $inQty = round((float) $ledger->quantity_in, 3);
        $outQty = round((float) $ledger->quantity_out, 3);

        $avgBefore = $this->resolveAverageBefore($ledger, $state['rate']);
        $inwardValue = $this->resolveInwardValue($ledger, $inQty);
        $outwardValue = $this->resolveOutwardValue($ledger, $outQty, $avgBefore);

        $prevQty = max(0.0, $state['qty']);
        $prevValue = max(0.0, $state['value']);

        // Prefer historical closing snapshot when present and qty matches stock_after.
        if ($ledger->closing_value !== null && $ledger->stock_after !== null
            && abs((float) $ledger->stock_after - round($prevQty + $inQty - $outQty, 3)) < 0.001
        ) {
            $closingQty = round(max(0.0, (float) $ledger->stock_after), 3);
            $closingValue = $closingQty <= 0.0001 ? 0.0 : round(max(0.0, (float) $ledger->closing_value), 2);
            $closingRate = $ledger->average_rate_after !== null
                ? (float) $ledger->average_rate_after
                : ($ledger->new_average_rate !== null ? (float) $ledger->new_average_rate : ($closingQty > 0 ? round($closingValue / $closingQty, 4) : 0.0));
        } else {
            $closingQty = round($prevQty + $inQty - $outQty, 3);
            $clamped = false;
            if ($closingQty < -0.0001) {
                $clamped = true;
            }
            $closingQty = max(0.0, $closingQty);

            if ($inQty > 0 && $outQty <= 0) {
                $closingValue = round($prevValue + $inwardValue, 2);
            } elseif ($outQty > 0 && $inQty <= 0) {
                $closingValue = round(max(0.0, $prevValue - $outwardValue), 2);
            } else {
                $closingValue = round(max(0.0, $prevValue + $inwardValue - $outwardValue), 2);
            }

            if ($closingQty <= 0.0001) {
                $closingQty = 0.0;
                $closingValue = 0.0;
                $closingRate = 0.0;
            } else {
                $closingRate = $ledger->average_rate_after !== null
                    ? (float) $ledger->average_rate_after
                    : ($ledger->new_average_rate !== null
                        ? (float) $ledger->new_average_rate
                        : round($closingValue / $closingQty, 4));
            }

            $state['clamped'] = $state['clamped'] || $clamped;
        }

        $inwardRate = $inQty > 0 ? round($inwardValue / $inQty, 4) : null;
        $outwardRate = $outQty > 0 ? round($outwardValue / $outQty, 4) : null;

        $particulars = $this->buildParticulars($ledger);
        $voucherUrl = $this->resolveVoucherUrl($ledger);
        $reference = $this->resolveReferenceMeta($ledger);
        $voucherNo = (string) ($ledger->reference_number ?: $ledger->batch_number ?: '');

        $txnRate = $inQty > 0
            ? ($inwardRate ?? (float) $ledger->rate)
            : ($outQty > 0 ? ($outwardRate ?? $this->resolveAverageBefore($ledger, 0.0)) : (float) $ledger->rate);
        $txnValue = $inQty > 0 ? $inwardValue : ($outQty > 0 ? $outwardValue : (float) ($ledger->transaction_value ?? 0));
        $dateTime = optional($ledger->transaction_date)?->timezone('Asia/Kolkata')?->format('d-m-Y');
        if ($ledger->created_at) {
            $dateTime = optional($ledger->created_at)->timezone('Asia/Kolkata')?->format('d-m-Y H:i')
                ?? $dateTime;
            // Prefer transaction date for the calendar day, keep time from created_at.
            $day = optional($ledger->transaction_date)?->format('d-m-Y');
            $time = optional($ledger->created_at)->timezone('Asia/Kolkata')?->format('H:i');
            if ($day && $time) {
                $dateTime = $day.' '.$time;
            }
        }

        $row = [
            'row_type' => 'transaction',
            'id' => $ledger->id,
            'date' => optional($ledger->transaction_date)?->format('d-m-Y') ?? '',
            'datetime' => $dateTime ?? '',
            'particulars' => $particulars,
            'voucher_type' => $ledger->transaction_type instanceof StockTransactionType
                ? $ledger->transaction_type->label()
                : (string) $ledger->transaction_type,
            'voucher_no' => $voucherNo,
            'voucher_reference_number' => $voucherNo !== '' ? $voucherNo : null,
            'voucher_url' => $voucherUrl,
            'reference_kind' => $reference['kind'],
            'reference_id' => $reference['id'],
            'voucher_reference_id' => $reference['id'],
            'batch_number' => $ledger->batch_number,
            'inward_qty' => $inQty > 0 ? $inQty : null,
            'inward_quantity' => $inQty > 0 ? $inQty : null,
            'inward_rate' => $inQty > 0 ? $inwardRate : null,
            'inward_value' => $inQty > 0 ? $inwardValue : null,
            'outward_qty' => $outQty > 0 ? $outQty : null,
            'outward_quantity' => $outQty > 0 ? $outQty : null,
            'outward_rate' => $outQty > 0 ? $outwardRate : null,
            'outward_value' => $outQty > 0 ? $outwardValue : null,
            'closing_qty' => $closingQty,
            'closing_quantity' => $closingQty,
            'closing_rate' => $closingRate,
            'average_purchase_rate' => $closingRate,
            'closing_value' => $closingValue,
            'rate' => $txnRate > 0 ? round((float) $txnRate, 4) : null,
            'transaction_value' => $txnValue > 0 ? round((float) $txnValue, 2) : null,
            'remarks' => $ledger->remarks,
            'remark' => $ledger->remarks,
            'created_by' => $ledger->createdBy?->name,
            'transaction_type' => $ledger->transaction_type instanceof StockTransactionType
                ? $ledger->transaction_type->value
                : (string) $ledger->transaction_type,
            'transaction_type_label' => $ledger->transaction_type instanceof StockTransactionType
                ? $ledger->transaction_type->label()
                : (string) $ledger->transaction_type,
        ];

        return [
            'state' => [
                'qty' => $closingQty,
                'value' => $closingValue,
                'rate' => $closingRate,
                'clamped' => $state['clamped'],
            ],
            'row' => $row,
        ];
    }

    private function resolveAverageBefore(StockLedger $ledger, float $runningRate): float
    {
        if ($ledger->average_rate_before !== null) {
            return (float) $ledger->average_rate_before;
        }

        if ($ledger->old_average_rate !== null) {
            return (float) $ledger->old_average_rate;
        }

        if ((float) $ledger->quantity_out > 0 && (float) $ledger->rate > 0) {
            return (float) $ledger->rate;
        }

        return $runningRate;
    }

    private function resolveInwardValue(StockLedger $ledger, float $inQty): float
    {
        if ($inQty <= 0) {
            return 0.0;
        }

        if ($ledger->inward_value !== null) {
            return round((float) $ledger->inward_value, 2);
        }

        if ($ledger->transaction_value !== null && (float) $ledger->quantity_out <= 0) {
            return round((float) $ledger->transaction_value, 2);
        }

        return round($inQty * (float) $ledger->rate, 2);
    }

    private function resolveOutwardValue(StockLedger $ledger, float $outQty, float $avgBefore): float
    {
        if ($outQty <= 0) {
            return 0.0;
        }

        if ($ledger->outward_value !== null) {
            return round((float) $ledger->outward_value, 2);
        }

        // Historical rate only — never present-day master rate.
        return round($outQty * $avgBefore, 2);
    }

    private function buildParticulars(StockLedger $ledger): string
    {
        $type = $ledger->transaction_type;
        $remarks = trim((string) ($ledger->remarks ?? ''));

        return match ($type) {
            StockTransactionType::RawMaterialInward,
            StockTransactionType::PackagingMaterialInward,
            StockTransactionType::Purchase => $this->inwardParticulars($ledger),
            StockTransactionType::ProductionConsumption => $this->productionConsumptionParticulars($ledger),
            StockTransactionType::ProductionOutput => filled($ledger->batch_number)
                ? 'Production Batch '.$ledger->batch_number
                : ($remarks !== '' ? $remarks : 'Finished Product Production'),
            StockTransactionType::Damage => $remarks !== '' ? 'Damage – '.$remarks : 'Damage',
            StockTransactionType::StockAdjustment => $remarks !== '' ? 'Stock Adjustment – '.$remarks : 'Stock Adjustment',
            StockTransactionType::Return,
            StockTransactionType::PurchaseReturn => $this->returnParticulars($ledger, $remarks),
            StockTransactionType::OpeningStock => 'Opening Stock',
            StockTransactionType::BatchReversal => $remarks !== '' ? 'Batch Reversal – '.$remarks : 'Batch Reversal',
            StockTransactionType::Dispatch => $remarks !== '' ? 'Dispatch – '.$remarks : 'Dispatch',
            default => $remarks !== '' ? ($type?->label() ?? 'Stock Movement').' – '.$remarks : ($type?->label() ?? 'Stock Movement'),
        };
    }

    private function inwardParticulars(StockLedger $ledger): string
    {
        $supplier = null;
        $invoice = $ledger->supplier_invoice_number;

        $ref = $ledger->relationLoaded('reference') ? $ledger->reference : null;
        if ($ref instanceof RawMaterialInward || $ref instanceof PackagingMaterialInward) {
            $supplier = $ref->displaySupplierName();
            $invoice = $invoice ?: $ref->supplier_invoice_number;
        } elseif ($ledger->reference_type && $ledger->reference_id) {
            if ($ledger->reference_type === RawMaterialInward::class) {
                $inward = RawMaterialInward::query()->find($ledger->reference_id);
                $supplier = $inward?->displaySupplierName();
                $invoice = $invoice ?: $inward?->supplier_invoice_number;
            } elseif ($ledger->reference_type === PackagingMaterialInward::class) {
                $inward = PackagingMaterialInward::query()->find($ledger->reference_id);
                $supplier = $inward?->displaySupplierName();
                $invoice = $invoice ?: $inward?->supplier_invoice_number;
            }
        }

        $supplier = filled($supplier) && $supplier !== '—' ? trim((string) $supplier) : null;
        $invoice = filled($invoice) ? trim((string) $invoice) : null;

        if ($supplier && $invoice) {
            return $supplier.' – Invoice '.$invoice;
        }

        if ($supplier) {
            return $supplier;
        }

        if ($invoice) {
            return 'Invoice '.$invoice;
        }

        return trim((string) $ledger->remarks) ?: 'Inward';
    }

    private function productionConsumptionParticulars(StockLedger $ledger): string
    {
        $batchNo = $ledger->batch_number ?: $ledger->reference_number;
        $time = null;

        if ($ledger->reference_type === ProductionBatch::class && $ledger->reference_id) {
            $batch = ProductionBatch::query()->find($ledger->reference_id);
            $batchNo = $batch?->batch_number ?: $batchNo;
            $time = optional($batch?->created_at)->timezone('Asia/Kolkata')?->format('H:i:s');
        }

        if (! $time && $ledger->created_at) {
            $time = $ledger->created_at->timezone('Asia/Kolkata')->format('H:i:s');
        }

        if (filled($batchNo) && filled($time)) {
            return 'Production Batch '.$batchNo.' – '.$time;
        }

        if (filled($batchNo)) {
            return 'Production Batch '.$batchNo;
        }

        return trim((string) $ledger->remarks) ?: 'Production Consumption';
    }

    private function returnParticulars(StockLedger $ledger, string $remarks): string
    {
        if ($ledger->reference_type === RawMaterialInward::class && $ledger->reference_id) {
            $inward = RawMaterialInward::query()->find($ledger->reference_id);
            $supplier = $inward?->displaySupplierName();
            if ($supplier && $supplier !== '—') {
                return 'Supplier: '.$supplier.($remarks !== '' ? ' | '.$remarks : '');
            }
        }

        if ($ledger->reference_type === PackagingMaterialInward::class && $ledger->reference_id) {
            $inward = PackagingMaterialInward::query()->find($ledger->reference_id);
            $supplier = $inward?->displaySupplierName();
            if ($supplier && $supplier !== '—') {
                return 'Supplier: '.$supplier.($remarks !== '' ? ' | '.$remarks : '');
            }
        }

        return $remarks !== '' ? $remarks : 'Return';
    }

    private function resolveVoucherUrl(StockLedger $ledger): ?string
    {
        if (! $ledger->reference_type || ! $ledger->reference_id) {
            return null;
        }

        try {
            return match ($ledger->reference_type) {
                RawMaterialInward::class => RawMaterialInwardResource::getUrl('view', ['record' => $ledger->reference_id]),
                PackagingMaterialInward::class => PackagingMaterialInwardResource::getUrl('view', ['record' => $ledger->reference_id]),
                ProductionBatch::class => ProductionBatchResource::getUrl('view', ['record' => $ledger->reference_id]),
                StockAdjustment::class => StockAdjustmentResource::getUrl('view', ['record' => $ledger->reference_id]),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mobile-safe reference metadata (kind + id for navigation; never show id in UI).
     *
     * @return array{kind: ?string, id: ?int}
     */
    private function resolveReferenceMeta(StockLedger $ledger): array
    {
        if (! $ledger->reference_type || ! $ledger->reference_id) {
            return ['kind' => null, 'id' => null];
        }

        $kind = match ($ledger->reference_type) {
            RawMaterialInward::class => 'raw_material_inward',
            PackagingMaterialInward::class => 'packaging_material_inward',
            ProductionBatch::class => 'production_batch',
            StockAdjustment::class => 'stock_adjustment',
            default => null,
        };

        return [
            'kind' => $kind,
            'id' => $kind !== null ? (int) $ledger->reference_id : null,
        ];
    }

    private function baseItemQuery(StockItemType $itemType, int $itemId): Builder
    {
        $query = StockLedger::query()->with(['rawMaterial', 'packagingMaterial', 'product', 'createdBy']);

        return match ($itemType) {
            StockItemType::RawMaterial => $query->where('item_type', StockItemType::RawMaterial)->where('raw_material_id', $itemId),
            StockItemType::PackagingMaterial => $query->where('item_type', StockItemType::PackagingMaterial)->where('packaging_material_id', $itemId),
            StockItemType::SemiFinished => $query->where('item_type', StockItemType::SemiFinished)->where('semi_finished_id', $itemId),
            StockItemType::FinishedProduct => $query->where('item_type', StockItemType::FinishedProduct)->where('product_id', $itemId),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyExtraFilters(Builder $query, array $filters): void
    {
        if (filled($filters['transaction_type'] ?? null)) {
            $query->where('transaction_type', $filters['transaction_type']);
        }

        if (filled($filters['voucher_number'] ?? null)) {
            $voucher = trim((string) $filters['voucher_number']);
            $query->where(function (Builder $q) use ($voucher): void {
                $q->where('reference_number', 'like', '%'.$voucher.'%')
                    ->orWhere('batch_number', 'like', '%'.$voucher.'%');
            });
        }

        if (filled($filters['production_batch'] ?? null)) {
            $batch = trim((string) $filters['production_batch']);
            $query->where(function (Builder $q) use ($batch): void {
                $q->where('batch_number', 'like', '%'.$batch.'%')
                    ->orWhere('reference_number', 'like', '%'.$batch.'%');
            });
        }

        if (filled($filters['supplier'] ?? null)) {
            $supplier = trim((string) $filters['supplier']);
            $like = '%'.$supplier.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('supplier_invoice_number', 'like', $like)
                    ->orWhere('remarks', 'like', $like)
                    ->orWhere(function (Builder $qq) use ($like): void {
                        $qq->where('reference_type', RawMaterialInward::class)
                            ->whereIn('reference_id', RawMaterialInward::query()
                                ->where('supplier_name', 'like', $like)
                                ->select('id'));
                    })
                    ->orWhere(function (Builder $qq) use ($like): void {
                        $qq->where('reference_type', PackagingMaterialInward::class)
                            ->whereIn('reference_id', PackagingMaterialInward::query()
                                ->where('supplier_name', 'like', $like)
                                ->select('id'));
                    });
            });
        }

        $inwardOnly = filter_var($filters['inward_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $outwardOnly = filter_var($filters['outward_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($inwardOnly && ! $outwardOnly) {
            $query->where('quantity_in', '>', 0);
        }

        if ($outwardOnly && ! $inwardOnly) {
            $query->where('quantity_out', '>', 0);
        }
    }

    /**
     * @param  callable(StockLedger): void  $callback
     */
    private function chronologicalChunk(Builder $query, callable $callback): void
    {
        (clone $query)
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(self::CHUNK_SIZE)
            ->each(function (StockLedger $ledger) use ($callback): void {
                $callback($ledger);
            });
    }

    /**
     * @return \Generator<int, StockLedger>
     */
    private function chronologicalCursor(Builder $query): \Generator
    {
        foreach ((clone $query)
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor() as $ledger) {
            yield $ledger;
        }
    }

    /**
     * @return array{
     *     name: string,
     *     code: string,
     *     category: string,
     *     unit: string,
     *     current_average_rate: float,
     *     available_quantity: float,
     *     minimum_stock: float|null,
     *     current_stock_value: float|null,
     *     stock_status: string|null
     * }
     */
    private function resolveItem(StockItemType $itemType, int $itemId): array
    {
        return match ($itemType) {
            StockItemType::RawMaterial => (function () use ($itemId): array {
                $item = RawMaterial::query()->findOrFail($itemId);
                $qty = (float) $item->current_stock;

                return [
                    'name' => (string) $item->material_name,
                    'code' => (string) $item->material_code,
                    'category' => (string) ($item->category ?? '-'),
                    'unit' => (string) ($item->unit ?? '-'),
                    'current_average_rate' => (float) $item->average_rate,
                    'available_quantity' => $qty,
                    'minimum_stock' => (float) $item->minimum_stock,
                    'current_stock_value' => (float) $item->current_stock_value,
                    'stock_status' => $item->isOutOfStock()
                        ? 'out_of_stock'
                        : ($item->isLowStock() ? 'low_stock' : 'available'),
                ];
            })(),
            StockItemType::PackagingMaterial => (function () use ($itemId): array {
                $item = PackagingMaterial::query()->findOrFail($itemId);
                $qty = (float) $item->current_stock;

                return [
                    'name' => (string) $item->packaging_name,
                    'code' => (string) $item->packaging_code,
                    'category' => (string) ($item->category ?? '-'),
                    'unit' => (string) ($item->unit ?? '-'),
                    'current_average_rate' => (float) $item->average_rate,
                    'available_quantity' => $qty,
                    'minimum_stock' => (float) $item->minimum_stock,
                    'current_stock_value' => (float) $item->current_stock_value,
                    'stock_status' => $qty <= 0
                        ? 'out_of_stock'
                        : ($qty <= (float) $item->minimum_stock ? 'low_stock' : 'available'),
                ];
            })(),
            StockItemType::SemiFinished => (function () use ($itemId): array {
                $item = SemiFinishedMaterial::query()->findOrFail($itemId);
                $qty = (float) $item->current_stock;
                $min = (float) $item->minimum_stock;

                return [
                    'name' => (string) $item->material_name,
                    'code' => (string) $item->material_code,
                    'category' => 'Semi-Finished',
                    'unit' => (string) ($item->unit ?? '-'),
                    'current_average_rate' => (float) $item->average_production_cost,
                    'available_quantity' => $qty,
                    'minimum_stock' => $min,
                    'current_stock_value' => (float) ($item->current_stock_value ?? 0),
                    'stock_status' => $qty <= 0
                        ? 'out_of_stock'
                        : ($qty <= $min ? 'low_stock' : 'available'),
                ];
            })(),
            StockItemType::FinishedProduct => (function () use ($itemId): array {
                $item = Product::query()->findOrFail($itemId);
                $qty = (float) ($item->current_finished_stock ?? 0);

                return [
                    'name' => (string) $item->product_name,
                    'code' => (string) $item->product_code,
                    'category' => (string) ($item->category ?? '-'),
                    'unit' => (string) ($item->production_unit ?: $item->uom ?: '-'),
                    'current_average_rate' => (float) $item->weighted_average_cost,
                    'available_quantity' => $qty,
                    'minimum_stock' => (float) ($item->minimum_finished_stock ?? $item->minimum_stock ?? 0),
                    'current_stock_value' => null,
                    'stock_status' => $item->isOutOfFinishedStock()
                        ? 'out_of_stock'
                        : ($item->isLowFinishedStock() ? 'low_stock' : 'available'),
                ];
            })(),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function emptyResult(array $filters): StockItemLedgerResult
    {
        $from = $this->normalizeDate($filters['from'] ?? null) ?? now('Asia/Kolkata')->startOfMonth()->toDateString();
        $to = $this->normalizeDate($filters['to'] ?? null) ?? now('Asia/Kolkata')->toDateString();

        return new StockItemLedgerResult(
            header: [
                'item_type' => (string) ($filters['item_type'] ?? ''),
                'item_type_label' => '',
                'item_id' => 0,
                'item_name' => '',
                'item_code' => '',
                'category' => '',
                'unit' => '',
                'available_quantity' => 0.0,
                'minimum_stock' => null,
                'stock_status' => null,
                'current_stock_value' => null,
                'available_stock_value' => null,
                'current_average_rate' => 0.0,
                'from' => $from,
                'to' => $to,
                'opening_qty' => 0.0,
                'opening_value' => 0.0,
                'opening_rate' => 0.0,
                'closing_qty' => 0.0,
                'closing_value' => 0.0,
                'closing_rate' => 0.0,
                'warning' => null,
            ],
            rows: [],
            totals: [
                'total_inward_qty' => 0.0,
                'total_inward_value' => 0.0,
                'total_outward_qty' => 0.0,
                'total_outward_value' => 0.0,
                'closing_qty' => 0.0,
                'closing_rate' => 0.0,
                'closing_value' => 0.0,
            ],
            totalTransactionCount: 0,
            page: 1,
            perPage: 50,
        );
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
