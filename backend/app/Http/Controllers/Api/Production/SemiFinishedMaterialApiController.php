<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Models\SemiFinishedMaterial;
use App\Models\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SemiFinishedMaterialApiController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SemiFinishedMaterial::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'stock_status' => ['nullable', 'in:low,out,available'],
        ]);

        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();

        $materials = SemiFinishedMaterial::query()
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('material_name', 'like', "%{$term}%")
                        ->orWhere('material_code', 'like', "%{$term}%");
                });
            })
            ->when(($validated['stock_status'] ?? null) === 'low', fn ($query) => $query
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0))
            ->when(($validated['stock_status'] ?? null) === 'out', fn ($query) => $query->where('current_stock', '<=', 0))
            ->when(($validated['stock_status'] ?? null) === 'available', fn ($query) => $query
                ->whereColumn('current_stock', '>', 'minimum_stock'))
            ->orderBy('material_name')
            ->paginate(20);

        return $this->paginated(
            'Semi-finished materials loaded successfully.',
            $materials,
            fn (SemiFinishedMaterial $material): array => $this->present($material, $showCosts),
        );
    }

    public function show(Request $request, SemiFinishedMaterial $semiFinishedMaterial): JsonResponse
    {
        $this->authorize('view', $semiFinishedMaterial);

        return $this->ok(
            'Semi-finished material loaded successfully.',
            $this->present($semiFinishedMaterial, $request->user()->canViewProductionCosts()),
        );
    }

    public function ledger(Request $request, SemiFinishedMaterial $semiFinishedMaterial): JsonResponse
    {
        $this->authorize('view', $semiFinishedMaterial);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $entries = StockLedger::query()
            ->where('semi_finished_id', $semiFinishedMaterial->id)
            ->when(filled($validated['from'] ?? null), fn ($q) => $q->whereDate('transaction_date', '>=', $validated['from']))
            ->when(filled($validated['to'] ?? null), fn ($q) => $q->whereDate('transaction_date', '<=', $validated['to']))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (StockLedger $ledger): array => [
                'id' => $ledger->id,
                'transaction_date' => optional($ledger->transaction_date)?->toDateString(),
                'transaction_type' => $ledger->transaction_type?->value,
                'transaction_type_label' => $ledger->transaction_type?->label(),
                'quantity_in' => (float) $ledger->quantity_in,
                'quantity_out' => (float) $ledger->quantity_out,
                'stock_after' => (float) $ledger->stock_after,
                'rate' => (float) $ledger->rate,
                'reference_number' => $ledger->reference_number,
                'batch_number' => $ledger->batch_number,
                'remarks' => $ledger->remarks,
            ]);

        return $this->ok('Semi-finished ledger loaded successfully.', [
            'material' => $this->present($semiFinishedMaterial, $request->user()->canViewProductionCosts()),
            'entries' => $entries,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SemiFinishedMaterial $material, bool $showCosts): array
    {
        $latestBatch = ProductionBatch::query()
            ->where('semi_finished_id', $material->id)
            ->where('status', ProductionBatchStatus::Completed)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first(['batch_number', 'manufacturing_date', 'production_date']);

        $data = [
            'id' => $material->id,
            'material_code' => $material->material_code,
            'material_name' => $material->material_name,
            'unit' => $material->unit,
            'current_stock' => (float) $material->current_stock,
            'minimum_stock' => (float) $material->minimum_stock,
            'status' => (bool) $material->status,
            'stock_status' => $this->stockStatus($material),
            'remarks' => $material->remarks,
            'latest_batch_number' => $latestBatch?->batch_number,
            'latest_manufacturing_date' => optional($latestBatch?->manufacturing_date ?? $latestBatch?->production_date)?->toDateString(),
        ];

        if ($showCosts) {
            $data['average_production_cost'] = (float) $material->average_production_cost;
            $data['average_rate'] = (float) $material->average_production_cost;
            $data['current_stock_value'] = (float) $material->current_stock_value;
        }

        return $data;
    }

    private function stockStatus(SemiFinishedMaterial $material): string
    {
        if ($material->isOutOfStock()) {
            return 'out';
        }

        if ($material->isLowStock()) {
            return 'low';
        }

        return 'available';
    }
}
