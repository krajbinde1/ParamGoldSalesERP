<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->string('search')->toString());

        $vehicles = Vehicle::query()
            ->active()
            ->when(
                filled($search),
                fn ($q) => $q->where(function ($inner) use ($search): void {
                    $term = '%'.$search.'%';
                    $inner->where('vehicle_number', 'like', $term)
                        ->orWhere('vehicle_name', 'like', $term)
                        ->orWhere('vehicle_type', 'like', $term);
                }),
            )
            ->orderBy('vehicle_number')
            ->limit(100)
            ->get()
            ->map(fn (Vehicle $vehicle): array => $this->present($vehicle))
            ->values();

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'vehicle_number' => Vehicle::normalizeVehicleNumber((string) $request->input('vehicle_number', '')),
        ]);

        $validated = $request->validate(
            [
                'vehicle_number' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('vehicles', 'vehicle_number'),
                ],
                'vehicle_name' => ['nullable', 'string', 'max:255'],
                'vehicle_type' => ['nullable', 'string', 'max:100'],
            ],
            [
                'vehicle_number.unique' => 'This vehicle number already exists.',
            ],
        );

        $vehicle = Vehicle::query()->create([
            'vehicle_number' => $validated['vehicle_number'],
            'vehicle_name' => filled($validated['vehicle_name'] ?? null)
                ? trim((string) $validated['vehicle_name'])
                : null,
            'vehicle_type' => filled($validated['vehicle_type'] ?? null)
                ? trim((string) $validated['vehicle_type'])
                : null,
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Vehicle created successfully.',
            'data' => $this->present($vehicle),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'vehicle_number' => $vehicle->vehicle_number,
            'vehicle_name' => $vehicle->vehicle_name,
            'vehicle_type' => $vehicle->vehicle_type,
            'is_active' => (bool) $vehicle->is_active,
            'display_label' => $vehicle->displayLabel(),
        ];
    }
}
