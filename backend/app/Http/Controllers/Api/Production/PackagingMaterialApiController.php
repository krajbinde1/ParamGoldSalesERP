<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\PackagingMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingMaterialApiController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PackagingMaterial::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'stock_status' => ['nullable', 'in:low,out,available'],
        ]);

        $user = $request->user();
        $showCosts = $user->canViewProductionCosts();

        $materials = PackagingMaterial::query()
            ->when(filled($validated['search'] ?? null), function ($query) use ($validated) {
                $term = $validated['search'];
                $query->where(function ($inner) use ($term) {
                    $inner->where('packaging_name', 'like', "%{$term}%")
                        ->orWhere('packaging_code', 'like', "%{$term}%");
                });
            })
            ->when(filled($validated['category'] ?? null), fn ($query) => $query->where('category', $validated['category']))
            ->when(($validated['stock_status'] ?? null) === 'low', fn ($query) => $query
                ->whereColumn('current_stock', '<=', 'minimum_stock')
                ->where('current_stock', '>', 0))
            ->when(($validated['stock_status'] ?? null) === 'out', fn ($query) => $query->where('current_stock', '<=', 0))
            ->when(($validated['stock_status'] ?? null) === 'available', fn ($query) => $query
                ->whereColumn('current_stock', '>', 'minimum_stock'))
            ->orderBy('packaging_name')
            ->paginate(20);

        return $this->paginated(
            'Packaging materials loaded successfully.',
            $materials,
            fn (PackagingMaterial $material): array => $this->present($material, $showCosts),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PackagingMaterial $material, bool $showCosts): array
    {
        $data = [
            'id' => $material->id,
            'material_code' => $material->packaging_code,
            'material_name' => $material->packaging_name,
            'category' => $material->category,
            'unit' => $material->unit,
            'current_stock' => (float) $material->current_stock,
            'minimum_stock' => (float) $material->minimum_stock,
            'stock_status' => $this->stockStatus($material),
        ];

        if ($showCosts) {
            $data['purchase_rate'] = (float) $material->purchase_rate;
            $data['average_rate'] = (float) $material->average_rate;
            $data['current_stock_value'] = (float) $material->current_stock_value;
        }

        return $data;
    }

    private function stockStatus(PackagingMaterial $material): string
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
