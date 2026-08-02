<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ShortageApiController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', RawMaterial::class);

        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();

        $rawShortages = RawMaterial::query()
            ->where('status', true)
            ->where(fn ($q) => $q->whereColumn('current_stock', '<=', 'minimum_stock')->orWhere('current_stock', '<=', 0))
            ->get()
            ->map(fn (RawMaterial $material): array => $this->presentRaw($material, $showCosts));

        $packagingShortages = PackagingMaterial::query()
            ->where('status', true)
            ->where(fn ($q) => $q->whereColumn('current_stock', '<=', 'minimum_stock')->orWhere('current_stock', '<=', 0))
            ->get()
            ->map(fn (PackagingMaterial $material): array => $this->presentPackaging($material, $showCosts));

        $finishedShortages = Product::query()
            ->where('manufacturing_enabled', true)
            ->where('status', true)
            ->where(fn ($q) => $q->whereColumn('current_finished_stock', '<=', 'minimum_finished_stock')->orWhere('current_finished_stock', '<=', 0))
            ->get()
            ->map(fn (Product $product): array => $this->presentFinished($product, $showCosts));

        /** @var Collection<int, array<string, mixed>> $all */
        $all = $rawShortages->concat($packagingShortages)->concat($finishedShortages)
            ->sortBy(fn (array $row): int => $row['stock_status'] === 'out' ? 0 : 1)
            ->values();

        return $this->ok('Shortage report loaded successfully.', [
            'items' => $all->all(),
            'summary' => [
                'total' => $all->count(),
                'out_of_stock' => $all->where('stock_status', 'out')->count(),
                'low_stock' => $all->where('stock_status', 'low')->count(),
                'raw_material_count' => $rawShortages->count(),
                'packaging_material_count' => $packagingShortages->count(),
                'finished_goods_count' => $finishedShortages->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRaw(RawMaterial $material, bool $showCosts): array
    {
        $data = [
            'item_type' => 'raw_material',
            'id' => $material->id,
            'code' => $material->material_code,
            'name' => $material->material_name,
            'category' => $material->category,
            'unit' => $material->unit,
            'current_stock' => (float) $material->current_stock,
            'minimum_stock' => (float) $material->minimum_stock,
            'shortage_quantity' => max(0, round((float) $material->minimum_stock - (float) $material->current_stock, 3)),
            'stock_status' => $material->isOutOfStock() ? 'out' : 'low',
        ];

        if ($showCosts) {
            $data['current_stock_value'] = (float) $material->current_stock_value;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPackaging(PackagingMaterial $material, bool $showCosts): array
    {
        $data = [
            'item_type' => 'packaging_material',
            'id' => $material->id,
            'code' => $material->packaging_code,
            'name' => $material->packaging_name,
            'category' => $material->category,
            'unit' => $material->unit,
            'current_stock' => (float) $material->current_stock,
            'minimum_stock' => (float) $material->minimum_stock,
            'shortage_quantity' => max(0, round((float) $material->minimum_stock - (float) $material->current_stock, 3)),
            'stock_status' => $material->isOutOfStock() ? 'out' : 'low',
        ];

        if ($showCosts) {
            $data['current_stock_value'] = (float) $material->current_stock_value;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFinished(Product $product, bool $showCosts): array
    {
        $data = [
            'item_type' => 'finished_goods',
            'id' => $product->id,
            'code' => $product->product_code,
            'name' => $product->product_name,
            'category' => $product->category,
            'unit' => $product->production_unit ?? $product->uom,
            'current_stock' => (float) $product->current_finished_stock,
            'minimum_stock' => (float) $product->minimum_finished_stock,
            'shortage_quantity' => max(0, round((float) $product->minimum_finished_stock - (float) $product->current_finished_stock, 3)),
            'stock_status' => $product->isOutOfFinishedStock() ? 'out' : 'low',
        ];

        if ($showCosts) {
            $data['finished_goods_value'] = round((float) $product->current_finished_stock * (float) $product->weighted_average_cost, 2);
        }

        return $data;
    }
}
