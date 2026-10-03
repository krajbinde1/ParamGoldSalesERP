<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use App\Services\TaDa\TaDaClaimSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EmployeeTaDaClaimController extends Controller
{
    public function __construct(
        private readonly TaDaClaimSubmissionService $submissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $today = TaDaClaim::businessNow()->startOfDay();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        $claims = TaDaClaim::query()->where('employee_id', $employee->id);

        $summary = [
            'total_claims' => (clone $claims)->count(),
            'month_claims' => (clone $claims)
                ->whereBetween('claim_date', [$monthStart, $monthEnd])
                ->count(),
            'pending_claims' => (clone $claims)
                ->where('status', TaDaClaim::STATUS_PENDING)
                ->count(),
            'approved_claims' => (clone $claims)
                ->where('status', TaDaClaim::STATUS_APPROVED)
                ->count(),
            'paid_claims' => (clone $claims)
                ->where('status', TaDaClaim::STATUS_PAID)
                ->count(),
        ];

        $recentClaims = (clone $claims)
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (TaDaClaim $claim): array => $this->formatListItem($claim))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_claims' => $recentClaims,
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        $employee = $request->user()->employee;
        $monthStart = Carbon::create(
            $validated['year'],
            $validated['month'],
            1,
            0,
            0,
            0,
            'Asia/Kolkata',
        )->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $claims = TaDaClaim::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('claim_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
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

    public function travelSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'claim_date' => ['required', 'date'],
        ]);

        return response()->json(
            $this->submissions->travelSummary($request->user()->employee, $validated['claim_date']),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $claim = $this->submissions->submit(
            $request->user()->employee,
            $request,
            UserRole::Employee->value,
        );

        return response()->json([
            'message' => 'TA/DA claim submitted successfully.',
            'data' => $this->formatDetail($claim),
        ], 201);
    }

    public function show(Request $request, TaDaClaim $taDaClaim): JsonResponse
    {
        $this->authorizeClaim($request, $taDaClaim);
        $taDaClaim->load('employee:id,full_name');

        return response()->json([
            'data' => $this->formatDetail($taDaClaim),
        ]);
    }

    public function rate(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        return response()->json([
            'per_km_rate' => TaDaSetting::resolvePerKmRate($employee),
        ]);
    }

    private function authorizeClaim(Request $request, TaDaClaim $claim): void
    {
        if ($claim->employee_id !== $request->user()->employee->id) {
            abort(403, 'You are not allowed to access this TA/DA claim.');
        }
    }

    private function formatListItem(TaDaClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'claim_date' => $claim->claim_date->toDateString(),
            'from_location' => $claim->from_location,
            'to_location' => $claim->to_location,
            'route' => $claim->routeLabel(),
            'travel_km' => (float) $claim->travel_km,
            'total_amount' => (float) $claim->total_amount,
            'status' => $claim->status,
            'status_label' => TaDaClaim::statusLabel($claim->status),
            'bill_photo_url' => $claim->billPhotoUrl(),
        ];
    }

    private function formatDetail(TaDaClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'employee_name' => $claim->employee?->full_name,
            'claim_date' => $claim->claim_date->toDateString(),
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
            'status_label' => TaDaClaim::statusLabel($claim->status),
        ];
    }
}
