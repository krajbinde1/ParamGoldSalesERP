<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TaDaClaim;
use App\Services\Notifications\TaDaClaimPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectorTaDaClaimController extends Controller
{
    public function __construct(
        private readonly TaDaClaimPushNotifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaDaClaim::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected,paid'],
        ]);

        $base = TaDaClaim::query()->submittedByManager();

        $claims = (clone $base)
            ->with('employee:id,full_name,employee_code')
            ->when(
                filled($validated['status'] ?? null),
                fn ($query) => $query->where('status', $validated['status']),
            )
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($claims->items())->map(fn (TaDaClaim $claim): array => [
                'id' => $claim->id,
                'claim_no' => $claim->claimNumber(),
                'claim_date' => $claim->claim_date->toDateString(),
                'submitted_at' => $claim->created_at?->timezone('Asia/Kolkata')->toDateTimeString(),
                'employee_name' => $claim->employee?->full_name,
                'submitter_role' => $claim->submitter_role,
                'submitter_role_label' => $claim->submitterRoleLabel(),
                'from_location' => $claim->from_location,
                'to_location' => $claim->to_location,
                'travel_km' => (float) $claim->travel_km,
                'total_amount' => (float) $claim->total_amount,
                'employee_remarks' => $claim->employee_remarks,
                'status' => $claim->status,
                'status_label' => $claim->displayStatusLabel(),
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
        if (! $taDaClaim->requiresDirectorApproval()) {
            abort(404);
        }

        $taDaClaim->load([
            'employee:id,full_name,employee_code',
            'approvedByUser:id,name',
            'rejectedByUser:id,name',
        ]);

        return response()->json([
            'data' => [
                'id' => $taDaClaim->id,
                'claim_no' => $taDaClaim->claimNumber(),
                'employee_name' => $taDaClaim->employee?->full_name,
                'employee_code' => $taDaClaim->employee?->employee_code,
                'submitter_role' => $taDaClaim->submitter_role,
                'submitter_role_label' => $taDaClaim->submitterRoleLabel(),
                'claim_date' => $taDaClaim->claim_date->toDateString(),
                'submitted_at' => $taDaClaim->created_at?->timezone('Asia/Kolkata')->toDateTimeString(),
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
                'status_label' => $taDaClaim->displayStatusLabel(),
                'bill_photo_url' => $taDaClaim->billPhotoUrl(),
                'approved_by_name' => $taDaClaim->approvedByUser?->name,
                'approved_at' => $taDaClaim->approved_at?->timezone('Asia/Kolkata')->toDateTimeString(),
                'rejected_by_name' => $taDaClaim->rejectedByUser?->name,
                'rejected_at' => $taDaClaim->rejected_at?->timezone('Asia/Kolkata')->toDateTimeString(),
                'approver_role' => $taDaClaim->approver_role,
            ],
        ]);
    }

    public function approve(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('approve', $taDaClaim);
        $taDaClaim->approve($request->user()->id, UserRole::Director->value);
        $fresh = $taDaClaim->fresh();
        $this->notifier->notifyManagerOfDecision($fresh, true);

        return response()->json([
            'message' => 'TA/DA claim approved successfully.',
            'data' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'status_label' => $fresh->displayStatusLabel(),
            ],
        ]);
    }

    public function reject(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorize('reject', $taDaClaim);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        $taDaClaim->reject($validated['remark'], $request->user()->id, UserRole::Director->value);
        $fresh = $taDaClaim->fresh();
        $this->notifier->notifyManagerOfDecision($fresh, false);

        return response()->json([
            'message' => 'TA/DA claim rejected successfully.',
            'data' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'status_label' => $fresh->displayStatusLabel(),
            ],
        ]);
    }
}
