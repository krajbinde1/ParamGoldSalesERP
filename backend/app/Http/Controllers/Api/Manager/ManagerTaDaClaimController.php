<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\TaDaClaim;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerTaDaClaimController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaDaClaim::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $base = TaDaClaim::query()->whereIn('employee_id', $reportIds === [] ? [0] : $reportIds);

        $claims = (clone $base)
            ->with('employee:id,full_name,employee_code')
            ->when(
                filled($validated['status'] ?? null),
                fn ($q) => $q->where('status', $validated['status']),
            )
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($claims->items())->map(fn (TaDaClaim $claim): array => [
                'id' => $claim->id,
                'claim_date' => $claim->claim_date->toDateString(),
                'employee_name' => $claim->employee?->full_name,
                'employee_code' => $claim->employee?->employee_code,
                'travel_km' => (float) $claim->travel_km,
                'per_km_rate' => (float) $claim->per_km_rate,
                'travel_amount' => (float) $claim->travel_amount,
                'da_amount' => (float) ($claim->da_amount ?? 0),
                'other_expense' => (float) $claim->other_expense,
                'total_amount' => (float) $claim->total_amount,
                'employee_remarks' => $claim->employee_remarks,
                'status' => $claim->status,
                'status_label' => TaDaClaim::statusLabel($claim->status),
            ])->values(),
            'meta' => [
                'current_page' => $claims->currentPage(),
                'last_page' => $claims->lastPage(),
                'total' => $claims->total(),
            ],
            'counts' => [
                'pending' => (clone $base)->where('status', TaDaClaim::STATUS_PENDING)->count(),
                'approved' => (clone $base)->where('status', TaDaClaim::STATUS_APPROVED)->count(),
                'rejected' => (clone $base)->where('status', TaDaClaim::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function show(TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('view', $taDaClaim);
        $taDaClaim->load('employee:id,full_name,employee_code');

        return response()->json([
            'data' => [
                'id' => $taDaClaim->id,
                'employee_name' => $taDaClaim->employee?->full_name,
                'employee_code' => $taDaClaim->employee?->employee_code,
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
                'approved_by' => $taDaClaim->approved_by,
                'approved_at' => $taDaClaim->approved_at?->toDateTimeString(),
                'rejected_by' => $taDaClaim->rejected_by,
                'rejected_at' => $taDaClaim->rejected_at?->toDateTimeString(),
                'status' => $taDaClaim->status,
                'status_label' => TaDaClaim::statusLabel($taDaClaim->status),
                'bill_photo_url' => $taDaClaim->billPhotoUrl(),
            ],
        ]);
    }

    public function approve(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('approve', $taDaClaim);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['remark'] ?? null)) {
            $taDaClaim->update(['admin_remark' => trim($validated['remark'])]);
        }

        $taDaClaim->approve($request->user()->id);

        return response()->json([
            'message' => 'TA/DA claim approved successfully.',
            'data' => ['id' => $taDaClaim->id, 'status' => $taDaClaim->fresh()->status],
        ]);
    }

    public function reject(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('reject', $taDaClaim);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        $taDaClaim->reject($validated['remark'], $request->user()->id);

        return response()->json([
            'message' => 'TA/DA claim rejected successfully.',
            'data' => ['id' => $taDaClaim->id, 'status' => $taDaClaim->fresh()->status],
        ]);
    }
}
