<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Crop;
use App\Models\MaharashtraDistrict;
use App\Models\MaharashtraTaluka;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldActivityMasterController extends Controller
{
    public function districts(): JsonResponse
    {
        $districts = MaharashtraDistrict::query()
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MaharashtraDistrict $district): array => $district->toApiArray())
            ->values();

        return response()->json(['data' => $districts]);
    }

    public function talukas(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'district_id' => ['required', 'integer', 'exists:maharashtra_districts,id'],
        ]);

        $talukas = MaharashtraTaluka::query()
            ->where('district_id', $validated['district_id'])
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (MaharashtraTaluka $taluka): array => $taluka->toApiArray())
            ->values();

        return response()->json(['data' => $talukas]);
    }

    public function crops(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        $crops = Crop::query()
            ->where('status', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Crop $crop): array => $crop->toApiArray())
            ->values();

        return response()->json(['data' => $crops]);
    }
}
