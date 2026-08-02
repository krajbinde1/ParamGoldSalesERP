<?php

namespace App\Http\Controllers\Api\Production;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedGoodsApiController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'stock_status' => ['nullable', 'in:low,out,available'],
        ]);

        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();

        $products = Product::query()
            ->with(['activeVariants'])
            ->where('manufacturing_enabled', true)
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('product_name', 'like', "%{$term}%")
                        ->orWhere('product_code', 'like', "%{$term}%");
                });
            })
            ->when(filled($validated['category'] ?? null), fn ($query) => $query->where('category', $validated['category']))
            ->when(($validated['stock_status'] ?? null) === 'low', fn ($query) => $query
                ->whereColumn('current_finished_stock', '<=', 'minimum_finished_stock')
                ->where('current_finished_stock', '>', 0))
            ->when(($validated['stock_status'] ?? null) === 'out', fn ($query) => $query->where('current_finished_stock', '<=', 0))
            ->when(($validated['stock_status'] ?? null) === 'available', fn ($query) => $query
                ->whereColumn('current_finished_stock', '>', 'minimum_finished_stock'))
            ->orderBy('product_name')
            ->paginate(20);

        return $this->paginated(
            'Finished goods loaded successfully.',
            $products,
            fn (Product $product): array => $this->present($product, $showCosts),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product, bool $showCosts): array
    {
        $latestBatch = ProductionBatch::query()
            ->where('product_id', $product->id)
            ->where('status', ProductionBatchStatus::Completed)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first(['batch_number', 'manufacturing_date', 'production_date', 'expiry_date']);

        $currentStock = (float) $product->current_finished_stock;
        // Reserved qty is not tracked on Product yet; dispatchable equals available FG stock.
        $reserved = 0.0;

        $data = [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'category' => $product->category,
            'unit' => $product->production_unit ?? $product->uom,
            'production_unit' => $product->production_unit ?? $product->uom,
            'current_stock' => $currentStock,
            'current_finished_stock' => $currentStock,
            'minimum_stock' => (float) $product->minimum_finished_stock,
            'stock_status' => $this->stockStatus($product),
            'batch_tracking_enabled' => (bool) $product->batch_tracking_enabled,
            'shelf_life_days' => $product->shelf_life_days,
            'latest_batch_number' => $latestBatch?->batch_number,
            'latest_manufacturing_date' => optional($latestBatch?->manufacturing_date ?? $latestBatch?->production_date)?->toDateString(),
            'latest_expiry_date' => optional($latestBatch?->expiry_date)?->toDateString(),
            'reserved_quantity' => $reserved,
            'dispatchable_quantity' => max(0, round($currentStock - $reserved, 3)),
            'variants' => $product->activeVariants->map(function ($variant) use ($showCosts): array {
                $row = [
                    'id' => $variant->id,
                    'variant_code' => $variant->variant_code,
                    'pack_label' => $variant->packLabel(),
                    'pack_size' => (float) $variant->pack_size,
                    'pack_unit' => $variant->pack_unit,
                    'current_stock' => (float) $variant->current_stock,
                    'stock_unit' => $variant->stock_unit,
                ];

                if ($showCosts) {
                    $row['average_production_cost'] = (float) $variant->average_production_cost;
                    $row['stock_value'] = (float) $variant->stock_value;
                }

                return $row;
            })->values()->all(),
        ];

        if ($showCosts) {
            $data['standard_production_cost'] = (float) $product->standard_production_cost;
            $data['latest_production_cost'] = (float) $product->latest_production_cost;
            $data['weighted_average_cost'] = (float) $product->weighted_average_cost;
            $data['finished_goods_value'] = round((float) $product->current_finished_stock * (float) $product->weighted_average_cost, 2);
        }

        return $data;
    }

    private function stockStatus(Product $product): string
    {
        if ($product->isOutOfFinishedStock()) {
            return 'out';
        }

        if ($product->isLowFinishedStock()) {
            return 'low';
        }

        return 'available';
    }
}
