<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\InventoryUnit;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\Inventory\InventoryUnitConversion;
use App\Services\Inventory\RawMaterialCreateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RawMaterialApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly InventoryUnitConversion $unitConversion,
        private readonly RawMaterialCreateService $createService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RawMaterial::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'stock_status' => ['nullable', 'in:low,out,available'],
            'status' => ['nullable', 'in:active,inactive'],
            'view' => ['nullable', 'in:master,stock'],
        ]);

        $view = $validated['view'] ?? 'stock';
        $user = $request->user();

        if ($view === 'master') {
            return $this->masterIndex($request, $validated, $user);
        }

        return $this->stockIndex($validated, $user);
    }

    public function show(RawMaterial $rawMaterial): JsonResponse
    {
        $this->authorize('view', $rawMaterial);

        return $this->ok('Raw material loaded successfully.', $this->presentMaster($rawMaterial, includeFormFields: true));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', RawMaterial::class);

        $validated = $request->validate($this->masterRules(creating: true));

        $qty = (float) ($validated['opening_stock_quantity']
            ?? $validated['opening_quantity']
            ?? 0);
        $value = (float) ($validated['opening_stock_value'] ?? 0);

        if ($qty > 0 && $value <= 0 && isset($validated['opening_purchase_rate'])) {
            // Legacy mobile payloads that still send purchase_rate (+ optional charges).
            $opening = [
                'quantity' => $qty,
                'purchase_rate' => $validated['opening_purchase_rate'] ?? 0,
                'gst_percentage' => $validated['opening_gst_percentage'] ?? 0,
                'freight' => $validated['opening_freight'] ?? 0,
                'other_charges' => $validated['opening_other_charges'] ?? 0,
                'date' => $validated['opening_date'] ?? now('Asia/Kolkata')->toDateString(),
                'remarks' => $validated['opening_remarks'] ?? null,
            ];
        } else {
            $opening = [
                'quantity' => $qty,
                'value' => $value,
                'date' => $validated['opening_date'] ?? now('Asia/Kolkata')->toDateString(),
                'remarks' => $validated['opening_remarks'] ?? null,
            ];
        }

        $materialData = [
            'material_name' => $validated['material_name'],
            'unit' => $validated['unit'],
            'minimum_stock' => $validated['minimum_stock'],
            'batch_tracking_enabled' => (bool) ($validated['batch_tracking_enabled'] ?? false),
            'expiry_tracking_enabled' => (bool) ($validated['expiry_tracking_enabled'] ?? false),
            'status' => (bool) ($validated['status'] ?? true),
            'remarks' => $validated['remarks'] ?? null,
            'category' => 'General',
        ];

        $material = $this->createService->create(
            materialData: $materialData,
            opening: $opening,
            user: $request->user(),
        );

        return $this->ok(
            ((float) ($opening['quantity'] ?? 0)) > 0
                ? 'Raw material created with opening stock.'
                : 'Raw material created successfully.',
            $this->presentMaster($material->fresh(), includeFormFields: true),
            201,
        );
    }

    public function update(Request $request, RawMaterial $rawMaterial): JsonResponse
    {
        $this->authorize('update', $rawMaterial);

        $validated = $request->validate($this->masterRules(creating: false));

        // Master edit only — never mutate stock quantities/values here.
        $rawMaterial->fill([
            'material_name' => $validated['material_name'],
            'unit' => $validated['unit'],
            'minimum_stock' => $validated['minimum_stock'],
            'batch_tracking_enabled' => (bool) ($validated['batch_tracking_enabled'] ?? false),
            'expiry_tracking_enabled' => (bool) ($validated['expiry_tracking_enabled'] ?? false),
            'status' => (bool) ($validated['status'] ?? true),
            'remarks' => $validated['remarks'] ?? null,
        ])->save();

        return $this->ok(
            'Raw material updated successfully.',
            $this->presentMaster($rawMaterial->fresh(), includeFormFields: true),
        );
    }

    /**
     * @param  array{search?: string|null, status?: string|null}  $validated
     */
    private function masterIndex(Request $request, array $validated, ?User $user): JsonResponse
    {
        $query = RawMaterial::query()
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('material_name', 'like', "%{$term}%")
                        ->orWhere('material_code', 'like', "%{$term}%");
                });
            })
            ->when(($validated['status'] ?? null) === 'active', fn ($query) => $query->where('status', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($query) => $query->where('status', false));

        $materials = $query
            ->orderBy('material_name')
            ->paginate(20);

        $canManage = $user?->can('create', RawMaterial::class) ?? false;

        return response()->json([
            'success' => true,
            'message' => 'Raw material masters loaded successfully.',
            'data' => collect($materials->items())
                ->map(fn (RawMaterial $material): array => $this->presentMaster($material))
                ->values(),
            'meta' => [
                'current_page' => $materials->currentPage(),
                'last_page' => $materials->lastPage(),
                'per_page' => $materials->perPage(),
                'total' => $materials->total(),
                'view' => 'master',
                'can_create' => $canManage,
                'can_update' => $canManage,
                'unit_options' => InventoryUnit::options(),
            ],
        ]);
    }

    /**
     * Legacy stock-enriched list (kept for existing consumers).
     * Prefer Inventory Stock Report API for stock qty/value/ledger UI.
     *
     * @param  array{search?: string|null, category?: string|null, stock_status?: string|null}  $validated
     */
    private function stockIndex(array $validated, ?User $user): JsonResponse
    {
        $showCosts = $this->canViewRawMaterialValuation($user);

        $query = $this->filteredStockQuery($validated);

        $totalRawMaterialValue = $showCosts
            ? round((float) (clone $query)->sum('current_stock_value'), 2)
            : null;

        $materials = (clone $query)
            ->orderBy('material_name')
            ->paginate(20);

        $items = collect($materials->items())
            ->map(fn (RawMaterial $material): array => $this->presentStock($material, $showCosts))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Raw materials loaded successfully.',
            'data' => $items,
            'meta' => [
                'current_page' => $materials->currentPage(),
                'last_page' => $materials->lastPage(),
                'per_page' => $materials->perPage(),
                'total' => $materials->total(),
                'view' => 'stock',
            ],
            'summary' => [
                'total_raw_material_value' => $totalRawMaterialValue,
            ],
        ]);
    }

    /**
     * Production supervisors need RM stock value on mobile Inventory even without
     * the director-only production_cost_view flag. Same stored WAVG as web.
     */
    private function canViewRawMaterialValuation(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->canViewProductionCosts()
            || $user->canViewInwardRates()
            || $user->canActAsProductionSupervisor();
    }

    /**
     * @param  array{search?: string|null, category?: string|null, stock_status?: string|null}  $validated
     * @return Builder<RawMaterial>
     */
    private function filteredStockQuery(array $validated): Builder
    {
        return RawMaterial::query()
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('material_name', 'like', "%{$term}%")
                        ->orWhere('material_code', 'like', "%{$term}%");
                });
            })
            ->when(filled($validated['category'] ?? null), fn ($query) => $query->where('category', $validated['category']))
            ->when(($validated['stock_status'] ?? null) === 'low', fn ($query) => $query
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0))
            ->when(($validated['stock_status'] ?? null) === 'out', fn ($query) => $query->where('current_stock', '<=', 0))
            ->when(($validated['stock_status'] ?? null) === 'available', fn ($query) => $query
                ->whereColumn('current_stock', '>', 'minimum_stock'));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMaster(RawMaterial $material, bool $includeFormFields = false): array
    {
        $data = [
            'id' => $material->id,
            'name' => $material->material_name,
            'code' => $material->material_code,
            'material_code' => $material->material_code,
            'material_name' => $material->material_name,
            'unit' => $material->unit,
            'status' => (bool) $material->status,
            'status_label' => $material->status ? 'Active' : 'Inactive',
            'minimum_stock' => (float) $material->minimum_stock,
            'batch_tracking_enabled' => (bool) $material->batch_tracking_enabled,
            'expiry_tracking_enabled' => (bool) $material->expiry_tracking_enabled,
        ];

        if ($includeFormFields) {
            $data['remarks'] = $material->remarks;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStock(RawMaterial $material, bool $showCosts): array
    {
        $availableQuantity = (float) $material->current_stock;
        $status = $this->stockStatus($material);

        $data = [
            'id' => $material->id,
            'name' => $material->material_name,
            'material_code' => $material->material_code,
            'material_name' => $material->material_name,
            'category' => $material->category,
            'unit' => $material->unit,
            'current_stock' => $availableQuantity,
            'available_quantity' => $availableQuantity,
            'minimum_stock' => (float) $material->minimum_stock,
            'stock_status' => $status,
            'batch_tracking_enabled' => (bool) $material->batch_tracking_enabled,
            'expiry_tracking_enabled' => (bool) $material->expiry_tracking_enabled,
        ];

        if ($showCosts) {
            $valuation = $this->resolveStockValue($material);
            $data['purchase_rate'] = (float) $material->purchase_rate;
            $data['average_rate'] = (float) $material->average_rate;
            $data['valuation_rate'] = $valuation['valuation_rate'];
            $data['valuation_rate_unit'] = $valuation['valuation_rate_unit'];
            $data['valuation_source'] = $valuation['valuation_source'];
            $data['current_stock_value'] = $valuation['stock_value'];
            $data['stock_value'] = $valuation['stock_value'];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function masterRules(bool $creating): array
    {
        $rules = [
            'material_name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', Rule::in(array_keys(InventoryUnit::options()))],
            'minimum_stock' => ['required', 'numeric', 'min:0'],
            'batch_tracking_enabled' => ['sometimes', 'boolean'],
            'expiry_tracking_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];

        if ($creating) {
            $rules = array_merge($rules, [
                'opening_stock_quantity' => ['nullable', 'numeric', 'min:0'],
                'opening_stock_value' => ['nullable', 'numeric', 'min:0'],
                'opening_date' => ['nullable', 'date'],
                // Legacy aliases (still accepted; Effective Rate remains server-computed).
                'opening_quantity' => ['nullable', 'numeric', 'min:0'],
                'opening_purchase_rate' => ['nullable', 'numeric', 'min:0'],
                'opening_gst_percentage' => ['nullable', 'numeric', 'min:0'],
                'opening_freight' => ['nullable', 'numeric', 'min:0'],
                'opening_other_charges' => ['nullable', 'numeric', 'min:0'],
                'opening_remarks' => ['nullable', 'string'],
            ]);
        }

        return $rules;
    }

    /**
     * Web source of truth: stored current_stock_value from WAVG (average_rate × stock).
     * Fallback only when stored value is missing/zero but a valid rate exists.
     *
     * @return array{
     *     stock_value: float,
     *     valuation_rate: float,
     *     valuation_rate_unit: string,
     *     valuation_source: string
     * }
     */
    private function resolveStockValue(RawMaterial $material): array
    {
        $stockUnit = (string) ($material->unit ?? '');
        $stockQty = (float) $material->current_stock;
        $storedValue = (float) $material->current_stock_value;

        $rateCandidates = [
            ['rate' => (float) $material->average_rate, 'source' => 'weighted_average'],
            ['rate' => (float) $material->purchase_rate, 'source' => 'average_purchase'],
        ];

        $chosenRate = 0.0;
        $source = 'none';
        foreach ($rateCandidates as $candidate) {
            if ($candidate['rate'] > 0) {
                $chosenRate = $candidate['rate'];
                $source = $candidate['source'];
                break;
            }
        }

        // Prefer stored WAVG valuation when present (matches InventoryReportService / Filament).
        if ($storedValue > 0 || ($stockQty <= 0 && $storedValue === 0.0)) {
            return [
                'stock_value' => round($storedValue, 2),
                'valuation_rate' => $chosenRate > 0 ? $chosenRate : (float) $material->average_rate,
                'valuation_rate_unit' => $stockUnit,
                'valuation_source' => $chosenRate > 0 ? $source : 'weighted_average',
            ];
        }

        // Stored value is zero but stock + rate exist — never silently return ₹0.
        if ($stockQty > 0 && $chosenRate > 0) {
            $stockValue = $this->multiplyStockByRate($stockQty, $stockUnit, $chosenRate, $stockUnit);

            return [
                'stock_value' => $stockValue,
                'valuation_rate' => $chosenRate,
                'valuation_rate_unit' => $stockUnit,
                'valuation_source' => $source,
            ];
        }

        return [
            'stock_value' => 0.0,
            'valuation_rate' => 0.0,
            'valuation_rate_unit' => $stockUnit,
            'valuation_source' => 'none',
        ];
    }

    /**
     * Multiply qty × rate only when units are compatible (or identical).
     * Rate on RawMaterial master is always per stock unit; conversion is defensive.
     */
    private function multiplyStockByRate(
        float $stockQty,
        string $stockUnit,
        float $rate,
        string $rateUnit,
    ): float {
        if ($stockQty <= 0 || $rate <= 0) {
            return 0.0;
        }

        if ($stockUnit === '' || $rateUnit === '' || $stockUnit === $rateUnit) {
            return round($stockQty * $rate, 2);
        }

        if (! $this->unitConversion->areCompatible($rateUnit, $stockUnit)) {
            return 0.0;
        }

        // Convert rate from rateUnit → stockUnit before multiply.
        // e.g. rate ₹/Ton with stock in Kg → rate_per_kg = rate / 1000
        $factor = $this->unitConversion->conversionFactor($stockUnit, $rateUnit);

        return round($stockQty * $rate * $factor, 2);
    }

    private function stockStatus(RawMaterial $material): string
    {
        if ($material->isOutOfStock()) {
            return 'out_of_stock';
        }

        if ($material->isLowStock()) {
            return 'low_stock';
        }

        return 'available';
    }
}
