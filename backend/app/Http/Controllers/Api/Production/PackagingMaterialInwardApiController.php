<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\PackagingMaterial;
use App\Models\PackagingMaterialInward;
use App\Models\Supplier;
use App\Services\Inventory\PackagingMaterialInwardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PackagingMaterialInwardApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly PackagingMaterialInwardService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PackagingMaterialInward::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'integer'],
            'packaging_material_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
        ]);

        $query = PackagingMaterialInward::query()
            ->with(['supplier', 'createdBy', 'items'])
            ->orderByDesc('inward_date')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['supplier_id'])) {
            $query->where('supplier_id', $validated['supplier_id']);
        }

        if (! empty($validated['packaging_material_id'])) {
            $query->whereHas('items', fn ($q) => $q->where('packaging_material_id', $validated['packaging_material_id']));
        }

        if (! empty($validated['from'])) {
            $query->whereDate('inward_date', '>=', $validated['from']);
        }

        if (! empty($validated['until'])) {
            $query->whereDate('inward_date', '<=', $validated['until']);
        }

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('inward_number', 'like', "%{$search}%")
                    ->orWhere('supplier_invoice_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%");
            });
        }

        $user = $request->user();
        $rows = $query->limit(100)->get()->map(fn (PackagingMaterialInward $inward) => $this->presentList($inward, $user));

        return $this->ok('Packaging material inwards loaded.', ['items' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        abort(403, 'Packaging material inward create is not available via the mobile API.');
    }

    public function show(Request $request, PackagingMaterialInward $inward): JsonResponse
    {
        $this->authorize('view', $inward);
        $inward->load(['items.packagingMaterial', 'supplier', 'createdBy']);

        return $this->ok('Packaging inward details loaded.', $this->presentDetail($inward, $request->user()));
    }

    public function update(Request $request, PackagingMaterialInward $inward): JsonResponse
    {
        abort(403, 'Packaging material inward update is not available via the mobile API.');
    }

    public function post(Request $request, PackagingMaterialInward $inward): JsonResponse
    {
        abort(403, 'Packaging material inward post is not available via the mobile API.');
    }

    public function cancel(Request $request, PackagingMaterialInward $inward): JsonResponse
    {
        abort(403, 'Packaging material inward cancel is not available via the mobile API.');
    }

    public function searchPackagingMaterials(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PackagingMaterialInward::class);
        $q = trim((string) $request->query('q', ''));

        $items = PackagingMaterial::query()
            ->where('status', true)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('packaging_name', 'like', "%{$q}%")
                        ->orWhere('packaging_code', 'like', "%{$q}%");
                });
            })
            ->orderBy('packaging_name')
            ->limit(30)
            ->get()
            ->map(fn (PackagingMaterial $m) => [
                'id' => $m->id,
                'material_code' => $m->packaging_code,
                'material_name' => $m->packaging_name,
                'packaging_code' => $m->packaging_code,
                'packaging_name' => $m->packaging_name,
                'unit' => $m->unit,
                'current_stock' => (float) $m->current_stock,
                'average_rate' => $request->user()->canViewInwardRates() ? (float) $m->average_rate : null,
                'purchase_rate' => $request->user()->canViewInwardRates() ? (float) $m->purchase_rate : null,
            ]);

        return $this->ok('Packaging materials loaded.', ['items' => $items]);
    }

    public function searchSuppliers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PackagingMaterialInward::class);

        $q = trim((string) $request->query('q', ''));

        $items = Supplier::query()
            ->where('status', true)
            ->when($q !== '', fn ($query) => $query->where('supplier_name', 'like', "%{$q}%"))
            ->orderBy('supplier_name')
            ->limit(30)
            ->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'supplier_code' => $s->supplier_code,
                'supplier_name' => $s->supplier_name,
                'phone' => $s->phone,
                'gstin' => $s->gstin,
            ]);

        return $this->ok('Suppliers loaded.', ['items' => $items]);
    }

    /**
     * @return array{header: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'inward_date' => ['required', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_invoice_number' => ['required', 'string', 'max:100'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.packaging_material_id' => ['required', 'integer', 'exists:packaging_materials,id'],
            'items.*.inward_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.basic_rate' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.freight_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.other_charges' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_percentage' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string'],
        ]);

        if (empty($validated['supplier_id']) && empty($validated['supplier_name'])) {
            throw ValidationException::withMessages(['supplier_id' => 'Supplier is required.']);
        }

        $header = collect($validated)->except('items')->all();
        $items = array_map(fn (array $item): array => [
            ...$item,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'freight_amount' => $item['freight_amount'] ?? 0,
            'other_charges' => $item['other_charges'] ?? 0,
            'gst_percentage' => $item['gst_percentage'] ?? 0,
        ], $validated['items']);

        return ['header' => $header, 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentList(PackagingMaterialInward $inward, $user): array
    {
        return [
            'id' => $inward->id,
            'inward_number' => $inward->inward_number,
            'inward_date' => optional($inward->inward_date)?->toDateString(),
            'supplier_name' => $inward->displaySupplierName(),
            'supplier_invoice_number' => $inward->supplier_invoice_number,
            'total_items' => $inward->total_items,
            'total_quantity' => (float) $inward->total_accepted_qty,
            'grand_total' => $user->canViewInwardRates() ? (float) $inward->grand_total : null,
            'status' => $inward->status->value,
            'status_label' => $inward->status->label(),
            'created_by' => $inward->createdBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(PackagingMaterialInward $inward, $user): array
    {
        $canRates = $user->canViewInwardRates();

        return [
            ...$this->presentList($inward, $user),
            'supplier_id' => $inward->supplier_id,
            'supplier_invoice_date' => optional($inward->supplier_invoice_date)?->toDateString(),
            'remarks' => $inward->remarks,
            'attachment_path' => $inward->attachment_path,
            'total_basic_value' => $canRates ? (float) $inward->total_basic_value : null,
            'total_discount' => $canRates ? (float) $inward->total_discount : null,
            'total_freight' => $canRates ? (float) $inward->total_freight : null,
            'total_other_charges' => $canRates ? (float) $inward->total_other_charges : null,
            'total_taxable_value' => $canRates ? (float) $inward->total_taxable_value : null,
            'total_gst' => $canRates ? (float) $inward->total_gst : null,
            'posted_at' => optional($inward->posted_at)?->toIso8601String(),
            'can_post' => $user->can('post', $inward),
            'can_cancel' => $user->can('cancel', $inward),
            'items' => $inward->items->map(function ($item) use ($canRates) {
                $stockBefore = $item->stock_before !== null
                    ? (float) $item->stock_before
                    : (float) ($item->packagingMaterial?->current_stock ?? 0);
                $oldAvg = $item->old_average_rate !== null
                    ? (float) $item->old_average_rate
                    : (float) ($item->packagingMaterial?->average_rate ?? 0);
                $qty = (float) $item->accepted_quantity;
                $eff = (float) $item->effective_unit_rate;
                $stockAfter = $item->stock_after !== null
                    ? (float) $item->stock_after
                    : round($stockBefore + $qty, 3);
                $newAvg = $item->new_average_rate !== null
                    ? (float) $item->new_average_rate
                    : $this->previewNewAverageRate($stockBefore, $oldAvg, $qty, $eff);

                return [
                    'id' => $item->id,
                    'packaging_material_id' => $item->packaging_material_id,
                    'material_code' => $item->material_code,
                    'material_name' => $item->material_name,
                    'inward_quantity' => $qty,
                    'unit' => $item->unit,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'basic_rate' => $canRates ? (float) $item->basic_rate : null,
                    'discount_amount' => $canRates ? (float) $item->discount_amount : null,
                    'freight_amount' => $canRates ? (float) $item->freight_amount : null,
                    'other_charges' => $canRates ? (float) $item->other_charges : null,
                    'gst_percentage' => $canRates ? (float) $item->gst_percentage : null,
                    'taxable_amount' => $canRates ? (float) $item->taxable_amount : null,
                    'gst_amount' => $canRates ? (float) $item->igst_amount : null,
                    'landed_cost' => $canRates ? (float) $item->landed_cost : null,
                    'effective_inventory_value' => $canRates ? (float) $item->landed_cost : null,
                    'effective_unit_rate' => $canRates ? $eff : null,
                    'old_average_rate' => $canRates ? $oldAvg : null,
                    'new_average_rate' => $canRates ? $newAvg : null,
                    'total_amount' => $canRates ? (float) $item->total_amount : null,
                    'remarks' => $item->remarks,
                ];
            })->values()->all(),
        ];
    }

    private function previewNewAverageRate(float $oldStock, float $oldAvg, float $qty, float $eff): float
    {
        $newStock = $oldStock + $qty;
        if ($qty <= 0) {
            return $oldAvg;
        }
        if ($oldStock <= 0 || $newStock <= 0) {
            return $eff;
        }

        return round((($oldStock * $oldAvg) + ($qty * $eff)) / $newStock, 4);
    }
}
