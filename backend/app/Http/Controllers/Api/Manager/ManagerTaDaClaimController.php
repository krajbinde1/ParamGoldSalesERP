<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use App\Services\Notifications\TaDaClaimPushNotifier;
use App\Services\Orders\ManagerOrderAccessService;
use App\Services\TaDa\TaDaClaimSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ManagerTaDaClaimController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
        private readonly TaDaClaimSubmissionService $submissions,
        private readonly TaDaClaimPushNotifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TaDaClaim::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $base = TaDaClaim::query()
            ->submittedByEmployee()
            ->whereIn('employee_id', $reportIds === [] ? [0] : $reportIds);

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

        $taDaClaim->approve($request->user()->id, UserRole::Manager->value);

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

        $taDaClaim->reject($validated['remark'], $request->user()->id, UserRole::Manager->value);

        return response()->json([
            'message' => 'TA/DA claim rejected successfully.',
            'data' => ['id' => $taDaClaim->id, 'status' => $taDaClaim->fresh()->status],
        ]);
    }

    public function myIndex(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $today = TaDaClaim::businessNow()->startOfDay();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();
        $claims = TaDaClaim::query()->where('employee_id', $employee->id);

        $recentClaims = (clone $claims)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (TaDaClaim $claim): array => $this->formatOwnListItem($claim))
            ->values();

        return response()->json([
            'summary' => [
                'total_claims' => (clone $claims)->count(),
                'month_claims' => (clone $claims)
                    ->whereBetween('claim_date', [$monthStart, $monthEnd])
                    ->count(),
                'pending_claims' => (clone $claims)->where('status', TaDaClaim::STATUS_PENDING)->count(),
                'approved_claims' => (clone $claims)->where('status', TaDaClaim::STATUS_APPROVED)->count(),
                'paid_claims' => (clone $claims)->where('status', TaDaClaim::STATUS_PAID)->count(),
            ],
            'recent_claims' => $recentClaims,
        ]);
    }

    public function myCalendar(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        $monthStart = Carbon::create(
            $validated['year'],
            $validated['month'],
            1,
            0,
            0,
            0,
            'Asia/Kolkata',
        )->startOfMonth();

        $claims = TaDaClaim::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('claim_date', [$monthStart->toDateString(), $monthStart->copy()->endOfMonth()->toDateString()])
            ->orderBy('claim_date')
            ->get(['id', 'claim_date', 'status'])
            ->map(fn (TaDaClaim $claim): array => [
                'id' => $claim->id,
                'claim_date' => $claim->claim_date->toDateString(),
                'status' => $claim->status,
            ])
            ->values();

        return response()->json([
            'month' => (int) $validated['month'],
            'year' => (int) $validated['year'],
            'claims' => $claims,
        ]);
    }

    public function myTravelSummary(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $validated = $request->validate([
            'claim_date' => ['required', 'date'],
        ]);

        return response()->json($this->submissions->travelSummary($employee, $validated['claim_date']));
    }

    public function myRate(Request $request): JsonResponse
    {
        return response()->json([
            'per_km_rate' => TaDaSetting::resolvePerKmRate($this->ownEmployee($request)),
        ]);
    }

    public function myStore(Request $request): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        $claim = $this->submissions->submit($employee, $request, UserRole::Manager->value);
        $this->notifier->notifyDirectorsOfManagerSubmission($claim);

        return response()->json([
            'message' => 'TA/DA claim submitted successfully.',
            'data' => $this->formatOwnDetail($claim),
        ], 201);
    }

    public function myShow(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $employee = $this->ownEmployee($request);
        if ((int) $taDaClaim->employee_id !== (int) $employee->id) {
            abort(403, 'You are not allowed to access this TA/DA claim.');
        }

        $taDaClaim->load('employee:id,full_name');

        return response()->json([
            'data' => $this->formatOwnDetail($taDaClaim),
        ]);
    }

    private function ownEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        if (! $employee instanceof Employee) {
            abort(403, 'Your login is not linked to an employee record.');
        }

        return $employee;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOwnListItem(TaDaClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'claim_no' => $claim->claimNumber(),
            'claim_date' => $claim->claim_date->toDateString(),
            'from_location' => $claim->from_location,
            'to_location' => $claim->to_location,
            'route' => $claim->routeLabel(),
            'travel_km' => (float) $claim->travel_km,
            'total_amount' => (float) $claim->total_amount,
            'status' => $claim->status,
            'status_label' => $claim->displayStatusLabel(),
            'bill_photo_url' => $claim->billPhotoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOwnDetail(TaDaClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'claim_no' => $claim->claimNumber(),
            'employee_name' => $claim->employee?->full_name,
            'submitter_role' => $claim->submitter_role,
            'submitter_role_label' => $claim->submitterRoleLabel(),
            'claim_date' => $claim->claim_date->toDateString(),
            'submitted_at' => $claim->created_at?->timezone('Asia/Kolkata')->toDateTimeString(),
            'from_location' => $claim->from_location,
            'to_location' => $claim->to_location,
            'route' => $claim->routeLabel(),
            'travel_km' => (float) $claim->travel_km,
            'per_km_rate' => (float) $claim->per_km_rate,
            'travel_amount' => (float) $claim->travel_amount,
            'da_amount' => (float) ($claim->da_amount ?? 0),
            'other_expense' => (float) $claim->other_expense,
            'total_amount' => (float) $claim->total_amount,
            'bill_photo_url' => $claim->billPhotoUrl(),
            'employee_remarks' => $claim->employee_remarks,
            'admin_remark' => $claim->admin_remark,
            'status' => $claim->status,
            'status_label' => $claim->displayStatusLabel(),
            'approved_at' => $claim->approved_at?->timezone('Asia/Kolkata')->toDateTimeString(),
            'rejected_at' => $claim->rejected_at?->timezone('Asia/Kolkata')->toDateTimeString(),
            'approver_role' => $claim->approver_role,
        ];
    }
}
