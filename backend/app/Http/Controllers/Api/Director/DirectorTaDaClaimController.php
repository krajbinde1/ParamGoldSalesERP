<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\TaDaClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorTaDaClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaDaClaim::class);

        $claims = TaDaClaim::query()
            ->with('employee:id,full_name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($claims->items())->map(fn (TaDaClaim $claim): array => [
                'id' => $claim->id,
                'claim_date' => $claim->claim_date->toDateString(),
                'employee_name' => $claim->employee?->full_name,
                'travel_km' => (float) $claim->travel_km,
                'total_amount' => (float) $claim->total_amount,
                'status' => $claim->status,
                'status_label' => TaDaClaim::statusLabel($claim->status),
            ])->values(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'total' => $claims->total(),
            ],
        ]);
    }

    public function show(TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('view', $taDaClaim);
        $taDaClaim->load('employee:id,full_name');

        return response()->json([
            'data' => [
                'id' => $taDaClaim->id,
                'employee_name' => $taDaClaim->employee?->full_name,
                'claim_date' => $taDaClaim->claim_date->toDateString(),
                'from_location' => $taDaClaim->from_location,
                'to_location' => $taDaClaim->to_location,
                'travel_km' => (float) $taDaClaim->travel_km,
                'per_km_rate' => (float) $taDaClaim->per_km_rate,
                'travel_amount' => (float) $taDaClaim->travel_amount,
                'da_amount' => (float) ($taDaClaim->da_amount ?? 0),
                'other_expense' => (float) $taDaClaim->other_expense,
                'total_amount' => (float) $taDaClaim->total_amount,
                'employee_remarks' => $taDaClaim->employee_remarks,
                'admin_remark' => $taDaClaim->admin_remark,
                'status' => $taDaClaim->status,
                'status_label' => TaDaClaim::statusLabel($taDaClaim->status),
                'bill_photo_url' => $taDaClaim->billPhotoUrl(),
            ],
        ]);
    }
}
