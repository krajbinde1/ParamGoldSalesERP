<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\BomItemAlternate;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Services\Inventory\BOMCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BomApiController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly BOMCalculationService $bomCalculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bom::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'output_type' => ['nullable', 'in:finished_product,semi_finished'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $status = $validated['status'] ?? 'active';
        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();

        $boms = Bom::query()
            ->with(['product', 'semiFinished'])
            ->when($status === 'active', fn ($q) => $q->where('status', BomStatus::Active))
            ->when($status === 'inactive', fn ($q) => $q->where('status', BomStatus::Inactive))
            ->when(filled($validated['output_type'] ?? null), fn ($q) => $q->where('output_type', $validated['output_type']))
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('bom_number', 'like', "%{$term}%")
                        ->orWhereHas('product', function ($productQuery) use ($term) {
                            $productQuery->where('product_name', 'like', "%{$term}%")
                                ->orWhere('product_code', 'like', "%{$term}%");
                        })
                        ->orWhereHas('semiFinished', function ($sfQuery) use ($term) {
                            $sfQuery->where('material_name', 'like', "%{$term}%")
                                ->orWhere('material_code', 'like', "%{$term}%");
                        });
                });
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate(20);

        return $this->paginated(
            'BOMs loaded successfully.',
            $boms,
            fn (Bom $bom): array => $this->presentBomListItem($bom, $showCosts),
        );
    }

    public function show(Request $request, Bom $bom): JsonResponse
    {
        $this->authorize('view', $bom);

        $bom->load([
            'product',
            'semiFinished',
            'items.rawMaterial',
            'items.packagingMaterial',
            'items.semiFinished',
            'items.approvedAlternates.rawMaterial',
            'items.approvedAlternates.packagingMaterial',
        ]);

        $showCosts = $request->user()->canViewProductionCosts();
        $outputType = $bom->output_type instanceof BomOutputType
            ? $bom->output_type->value
            : (string) $bom->output_type;
        $outputName = $outputType === BomOutputType::SemiFinished->value
            ? ($bom->semiFinished?->material_name ?? 'Semi-finished')
            : ($bom->product?->product_name ?? 'Finished product');
        $bomSummary = $this->presentBomSummary(
            $this->bomCalculator->summarizeBom($bom, $bom->items),
            $showCosts,
        );

        return $this->ok('BOM details loaded successfully.', [
            'bom' => [
                'id' => $bom->id,
                'bom_number' => $bom->bom_number,
                'bom_version' => $bom->bom_version,
                'output_type' => $outputType,
                'output_type_label' => $outputType === BomOutputType::SemiFinished->value ? 'SF' : 'FG',
                'product_id' => $bom->product_id,
                'semi_finished_id' => $bom->semi_finished_id,
                'product_name' => $outputName,
                'output_item_id' => $bom->product_id ?? $bom->semi_finished_id,
                'output_item_name' => $outputName,
                'status' => $bom->status instanceof \BackedEnum ? $bom->status->value : (string) $bom->status,
                'status_label' => $bom->status instanceof BomStatus ? $bom->status->label() : (string) $bom->status,
                'effective_date' => $bom->effective_date?->toDateString(),
                'batch_quantity' => (float) $bom->batch_quantity,
                'batch_unit' => (string) $bom->batch_unit,
                'formula_quantity_label' => $bom->formulaQuantityLabel(),
                'notes' => $bom->notes,
                'formula_summary' => $bomSummary,
                'bom_summary' => $bomSummary,
            ],
            'items' => $bom->items->map(fn (BomItem $item): array => $this->presentBomItem($item, $showCosts))->values()->all(),
            'can_view_costs' => $showCosts,
        ]);
    }

    public function manufacturableProducts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $products = Product::query()
            ->where('status', true)
            ->orderBy('product_name')
            ->get([
                'id', 'product_code', 'product_name', 'category', 'uom', 'production_unit',
                'standard_batch_size', 'current_finished_stock', 'minimum_finished_stock',
                'shelf_life_days', 'batch_tracking_enabled',
            ])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'label' => $product->displayLabel(),
                'category' => $product->category,
                'unit' => $product->production_unit ?? $product->uom,
                'production_unit' => $product->production_unit ?? $product->uom,
                'standard_batch_size' => $product->standard_batch_size !== null ? (float) $product->standard_batch_size : null,
                'current_finished_stock' => (float) $product->current_finished_stock,
                'minimum_finished_stock' => (float) $product->minimum_finished_stock,
                'shelf_life_days' => $product->shelf_life_days,
                'batch_tracking_enabled' => (bool) $product->batch_tracking_enabled,
                'has_active_bom' => $this->bomCalculator->getActiveBomForProduct($product) !== null,
            ])
            ->values();

        return $this->ok('Manufacturable products loaded successfully.', $products);
    }

    public function manufacturableSemiFinished(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SemiFinishedMaterial::class);

        $materials = SemiFinishedMaterial::query()
            ->where('status', true)
            ->orderBy('material_name')
            ->get(['id', 'material_code', 'material_name', 'unit', 'current_stock', 'minimum_stock'])
            ->map(fn (SemiFinishedMaterial $material): array => [
                'id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'label' => trim($material->material_code.' — '.$material->material_name),
                'unit' => $material->unit,
                'current_stock' => (float) $material->current_stock,
                'minimum_stock' => (float) $material->minimum_stock,
                'has_active_bom' => $this->bomCalculator->getActiveBomForSemiFinished($material) !== null,
            ])
            ->values();

        return $this->ok('Manufacturable semi-finished materials loaded successfully.', $materials);
    }

    public function activeBom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'output_type' => ['nullable', 'in:finished_product,semi_finished'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'semi_finished_id' => ['nullable', 'integer', 'exists:semi_finished_materials,id'],
            'planned_quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $outputType = $validated['output_type'] ?? BomOutputType::FinishedProduct->value;

        if ($outputType === BomOutputType::SemiFinished->value) {
            $semiFinishedId = (int) ($validated['semi_finished_id'] ?? 0);
            if ($semiFinishedId < 1) {
                throw ValidationException::withMessages([
                    'semi_finished_id' => 'semi_finished_id is required for semi-finished BOM lookup.',
                ]);
            }

            $material = SemiFinishedMaterial::query()->findOrFail($semiFinishedId);
            $this->authorize('view', $material);
            $bom = $this->bomCalculator->getActiveBomForSemiFinished($material);

            if ($bom === null) {
                throw ValidationException::withMessages([
                    'semi_finished_id' => 'No active BOM found for the selected semi-finished material.',
                ]);
            }

            $outputName = $material->material_name;
            $outputId = $material->id;
        } else {
            $productId = (int) ($validated['product_id'] ?? 0);
            if ($productId < 1) {
                throw ValidationException::withMessages([
                    'product_id' => 'product_id is required for finished product BOM lookup.',
                ]);
            }

            $product = Product::query()->findOrFail($productId);
            $this->authorize('view', $product);
            $bom = $this->bomCalculator->getActiveBomForProduct($product);

            if ($bom === null) {
                throw ValidationException::withMessages([
                    'product_id' => 'No active BOM found for the selected product.',
                ]);
            }

            $outputName = $product->product_name;
            $outputId = $product->id;
            $material = null;
        }

        $bom->load(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished', 'items.approvedAlternates.rawMaterial', 'items.approvedAlternates.packagingMaterial']);

        $this->bomCalculator->assertActiveBomFormulaIsComplete($bom);

        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();
        $bomSummary = $this->presentBomSummary(
            $this->bomCalculator->summarizeBom($bom, $bom->items),
            $showCosts,
        );

        return $this->ok('Active BOM loaded successfully.', [
            'bom' => [
                'id' => $bom->id,
                'bom_number' => $bom->bom_number,
                'bom_version' => $bom->bom_version,
                'output_type' => $outputType,
                'product_id' => $bom->product_id,
                'semi_finished_id' => $bom->semi_finished_id,
                'product_name' => $outputName,
                'output_item_id' => $outputId,
                'output_item_name' => $outputName,
                'status' => $bom->status->value,
                'effective_date' => $bom->effective_date?->toDateString(),
                'batch_quantity' => (float) $bom->batch_quantity,
                'batch_unit' => (string) $bom->batch_unit,
                'formula_quantity_label' => $bom->formulaQuantityLabel(),
                'notes' => $bom->notes,
                'formula_summary' => $bomSummary,
                'bom_summary' => $bomSummary,
            ],
            'formula_summary' => $bomSummary,
            'bom_summary' => $bomSummary,
            'items' => $bom->items->map(fn (BomItem $item): array => $this->presentBomItem($item, $showCosts))->values()->all(),
        ]);
    }

    public function alternates(Request $request, BomItem $bomItem): JsonResponse
    {
        $this->authorize('view', $bomItem->bom);

        $bomItem->load(['approvedAlternates.rawMaterial', 'approvedAlternates.packagingMaterial']);
        $showCosts = $request->user()->canViewProductionCosts();

        $alternates = $bomItem->approvedAlternates->map(
            fn (BomItemAlternate $alternate): array => $this->presentAlternate($alternate, $showCosts)
        )->values()->all();

        return $this->ok('Approved alternates loaded successfully.', $alternates);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBomListItem(Bom $bom, bool $showCosts): array
    {
        $outputType = $bom->output_type instanceof BomOutputType
            ? $bom->output_type->value
            : (string) $bom->output_type;
        $outputName = $outputType === BomOutputType::SemiFinished->value
            ? ($bom->semiFinished?->material_name ?? 'Semi-finished')
            : ($bom->product?->product_name ?? 'Finished product');

        return [
            'id' => $bom->id,
            'bom_number' => $bom->bom_number,
            'output_type' => $outputType,
            'output_type_label' => $outputType === BomOutputType::SemiFinished->value ? 'SF' : 'FG',
            'output_item_name' => $outputName,
            'product_id' => $bom->product_id,
            'semi_finished_id' => $bom->semi_finished_id,
            'batch_quantity' => (float) $bom->batch_quantity,
            'batch_unit' => (string) $bom->batch_unit,
            'formula_quantity_label' => $bom->formulaQuantityLabel(),
            'effective_date' => $bom->effective_date?->toDateString(),
            'status' => $bom->status instanceof \BackedEnum ? $bom->status->value : (string) $bom->status,
            'status_label' => $bom->status instanceof BomStatus ? $bom->status->label() : (string) $bom->status,
            'can_view_costs' => $showCosts,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function presentBomSummary(array $summary, bool $showCosts): array
    {
        $payload = [
            'formula_quantity' => $summary['formula_quantity'],
            'batch_unit' => $summary['batch_unit'],
            'formula_unit' => $summary['formula_unit'],
            'formula_for_label' => $summary['formula_for_label'],
            'total_items' => $summary['total_items'],
            'raw_material_items' => $summary['raw_material_items'],
            'packaging_material_items' => $summary['packaging_material_items'],
            'semi_finished_items' => $summary['semi_finished_items'] ?? 0,
        ];

        if ($showCosts) {
            $payload['estimated_raw_material_cost'] = $summary['estimated_raw_material_cost'];
            $payload['estimated_packaging_cost'] = $summary['estimated_packaging_cost'];
            $payload['estimated_semi_finished_cost'] = $summary['estimated_semi_finished_cost'] ?? 0;
            $payload['estimated_total_bom_cost'] = $summary['estimated_total_bom_cost'];
            $payload['estimated_cost_per_finished_unit'] = $summary['estimated_cost_per_finished_unit'];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBomItem(BomItem $item, bool $showCosts): array
    {
        $materialName = match ($item->item_type) {
            BomItemType::RawMaterial => $item->rawMaterial?->material_name ?? 'Raw material',
            BomItemType::PackagingMaterial => $item->packagingMaterial?->packaging_name ?? 'Packaging material',
            BomItemType::SemiFinished => $item->semiFinished?->material_name ?? 'Semi-finished',
        };

        $averageRate = match ($item->item_type) {
            BomItemType::RawMaterial => (float) ($item->rawMaterial?->average_rate ?? 0),
            BomItemType::PackagingMaterial => (float) ($item->packagingMaterial?->average_rate ?? 0),
            BomItemType::SemiFinished => (float) ($item->semiFinished?->average_production_cost ?? 0),
        };

        $currentStock = match ($item->item_type) {
            BomItemType::RawMaterial => (float) ($item->rawMaterial?->current_stock ?? 0),
            BomItemType::PackagingMaterial => (float) ($item->packagingMaterial?->current_stock ?? 0),
            BomItemType::SemiFinished => (float) ($item->semiFinished?->current_stock ?? 0),
        };

        return [
            'id' => $item->id,
            'item_type' => $item->item_type->value,
            'raw_material_id' => $item->raw_material_id,
            'packaging_material_id' => $item->packaging_material_id,
            'semi_finished_id' => $item->semi_finished_id,
            'material_name' => $materialName,
            'unit' => $item->unit,
            'formulation_unit' => $item->unit,
            'required_quantity' => (float) $item->required_quantity,
            'inventory_unit' => $item->inventory_unit,
            'inventory_equivalent_quantity' => $item->inventory_equivalent_quantity !== null
                ? (float) $item->inventory_equivalent_quantity
                : null,
            'conversion_factor' => $item->conversion_factor !== null
                ? (float) $item->conversion_factor
                : null,
            'is_optional' => (bool) $item->is_optional,
            'remarks' => $item->remarks,
            'average_rate' => $showCosts ? $averageRate : null,
            'current_stock' => $currentStock,
            'alternates' => $item->approvedAlternates
                ->map(fn (BomItemAlternate $alternate): array => $this->presentAlternate($alternate, $showCosts))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAlternate(BomItemAlternate $alternate, bool $showCosts): array
    {
        $isRaw = $alternate->alternate_item_type === BomItemType::RawMaterial
            || ($alternate->raw_material_id !== null && $alternate->packaging_material_id === null);

        $name = $isRaw
            ? ($alternate->rawMaterial?->material_name ?? RawMaterial::query()->find($alternate->raw_material_id)?->material_name)
            : ($alternate->packagingMaterial?->packaging_name ?? PackagingMaterial::query()->find($alternate->packaging_material_id)?->packaging_name);

        $rate = $isRaw
            ? (float) ($alternate->rawMaterial?->average_rate ?? 0)
            : (float) ($alternate->packagingMaterial?->average_rate ?? 0);

        $stock = $isRaw
            ? (float) ($alternate->rawMaterial?->current_stock ?? 0)
            : (float) ($alternate->packagingMaterial?->current_stock ?? 0);

        return [
            'id' => $alternate->id,
            'item_type' => $isRaw ? BomItemType::RawMaterial->value : BomItemType::PackagingMaterial->value,
            'raw_material_id' => $alternate->raw_material_id,
            'packaging_material_id' => $alternate->packaging_material_id,
            'material_name' => $name,
            'conversion_ratio' => (float) $alternate->conversion_ratio,
            'average_rate' => $showCosts ? $rate : null,
            'current_stock' => $stock,
            'is_approved' => (bool) $alternate->is_approved,
        ];
    }
}
