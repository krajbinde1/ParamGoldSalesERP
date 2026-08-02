<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\StockItemType;
use App\Enums\StockTransactionType;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Browsable stock ledger list for Production Supervisor mobile Inventory tab.
 */
class StockLedgerBrowseApiController extends Controller
{
    use RespondsWithJson;

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canAccessInventoryModule(), 403);

        $validated = $request->validate([
            'item_type' => ['nullable', 'string', 'in:'.implode(',', array_column(StockItemType::cases(), 'value'))],
            'transaction_type' => ['nullable', 'string', 'in:'.implode(',', array_column(StockTransactionType::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:255'],
            'batch_number' => ['nullable', 'string', 'max:191'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StockLedger::query()
            ->with(['rawMaterial', 'packagingMaterial', 'product', 'semiFinished', 'createdBy'])
            ->when(filled($validated['item_type'] ?? null), fn ($q) => $q->where('item_type', $validated['item_type']))
            ->when(filled($validated['transaction_type'] ?? null), fn ($q) => $q->where('transaction_type', $validated['transaction_type']))
            ->when(filled($validated['batch_number'] ?? null), fn ($q) => $q->where('batch_number', 'like', '%'.$validated['batch_number'].'%'))
            ->when(filled($validated['from'] ?? null), fn ($q) => $q->whereDate('transaction_date', '>=', $validated['from']))
            ->when(filled($validated['to'] ?? null), fn ($q) => $q->whereDate('transaction_date', '<=', $validated['to']))
            ->when(filled($validated['search'] ?? null), function ($q) use ($validated) {
                $term = $validated['search'];
                $q->where(function ($inner) use ($term) {
                    $inner->whereHas('rawMaterial', fn ($rq) => $rq->where('material_name', 'like', "%{$term}%")
                        ->orWhere('material_code', 'like', "%{$term}%"))
                        ->orWhereHas('packagingMaterial', fn ($pq) => $pq->where('packaging_name', 'like', "%{$term}%")
                            ->orWhere('packaging_code', 'like', "%{$term}%"))
                        ->orWhereHas('product', fn ($fq) => $fq->where('product_name', 'like', "%{$term}%")
                            ->orWhere('product_code', 'like', "%{$term}%"))
                        ->orWhereHas('semiFinished', fn ($sq) => $sq->where('material_name', 'like', "%{$term}%")
                            ->orWhere('material_code', 'like', "%{$term}%"))
                        ->orWhere('reference_number', 'like', "%{$term}%")
                        ->orWhere('batch_number', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        $rows = $query->paginate(30);

        return $this->paginated(
            'Stock ledger loaded successfully.',
            $rows,
            fn (StockLedger $ledger): array => $this->present($ledger),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockLedger $ledger): array
    {
        [$itemName, $unit] = match ($ledger->item_type) {
            StockItemType::RawMaterial => [
                (string) ($ledger->rawMaterial?->material_name ?? '-'),
                (string) ($ledger->rawMaterial?->unit ?? ''),
            ],
            StockItemType::PackagingMaterial => [
                (string) ($ledger->packagingMaterial?->packaging_name ?? '-'),
                (string) ($ledger->packagingMaterial?->unit ?? ''),
            ],
            StockItemType::SemiFinished => [
                (string) ($ledger->semiFinished?->material_name ?? '-'),
                (string) ($ledger->semiFinished?->unit ?? ''),
            ],
            StockItemType::FinishedProduct => [
                (string) ($ledger->product?->product_name ?? '-'),
                (string) ($ledger->product?->production_unit ?? $ledger->product?->uom ?? ''),
            ],
            default => ['-', ''],
        };

        return [
            'id' => $ledger->id,
            'transaction_date' => optional($ledger->transaction_date)?->toDateString(),
            'created_at' => optional($ledger->created_at)?->toDateTimeString(),
            'item_type' => $ledger->item_type?->value,
            'item_type_label' => $ledger->item_type?->label(),
            'item_name' => $itemName,
            'unit' => $unit,
            'transaction_type' => $ledger->transaction_type?->value,
            'transaction_type_label' => $ledger->transaction_type?->label(),
            'reference_number' => $ledger->reference_number,
            'batch_number' => $ledger->batch_number,
            'quantity_in' => (float) $ledger->quantity_in,
            'quantity_out' => (float) $ledger->quantity_out,
            'stock_before' => (float) $ledger->stock_before,
            'stock_after' => (float) $ledger->stock_after,
            'created_by_name' => $ledger->createdBy?->name,
            'remarks' => $ledger->remarks,
        ];
    }
}
