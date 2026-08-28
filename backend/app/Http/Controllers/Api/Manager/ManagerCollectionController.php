<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Employee;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Orders\ManagerOrderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerCollectionController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date_from' => ['nullable', 'date', 'required_if:period,custom'],
            'date_to' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:date_from'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:pending,received,not_received,rejected'],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        if ($employeeId !== null && ! in_array($employeeId, $reportIds, true)) {
            abort(403, 'You can only view collections of employees reporting to you.');
        }

        $filterIds = $employeeId !== null
            ? [$employeeId]
            : ($reportIds === [] ? [0] : $reportIds);

        $period = $validated['period'] ?? 'month';
        $startDate = $validated['date_from'] ?? $validated['start_date'] ?? null;
        $endDate = $validated['date_to'] ?? $validated['end_date'] ?? null;
        $range = $this->metrics->resolveDateRange($period, $startDate, $endDate);

        $base = Collection::query()->whereIn('sales_employee_id', $filterIds);
        $today = Collection::businessToday()->toDateString();
        $monthStart = Collection::businessToday()->copy()->startOfMonth()->toDateString();
        $monthEnd = Collection::businessToday()->copy()->endOfMonth()->toDateString();

        $summary = [
            'total_collection' => round((float) (clone $base)
                ->where('status', Collection::STATUS_RECEIVED)
                ->sum('amount'), 2),
            'today_collection' => round((float) (clone $base)
                ->where('status', Collection::STATUS_RECEIVED)
                ->whereDate('collection_date', $today)
                ->sum('amount'), 2),
            'month_collection' => round((float) (clone $base)
                ->where('status', Collection::STATUS_RECEIVED)
                ->whereBetween('collection_date', [$monthStart, $monthEnd])
                ->sum('amount'), 2),
            'pending_entries' => (clone $base)
                ->where('status', Collection::STATUS_PENDING)
                ->count(),
        ];

        $list = (clone $base)
            ->with([
                'dealer:id,firm_name',
                'salesEmployee:id,full_name,employee_code',
            ])
            ->whereBetween('collection_date', [
                $range['start']->toDateString(),
                $range['end']->toDateString(),
            ])
            ->when(
                filled($validated['status'] ?? null),
                fn ($q) => $q->where('status', $validated['status']),
            )
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->paginate(50);

        $employees = $reportIds === []
            ? collect()
            : Employee::query()
                ->whereIn('id', $reportIds)
                ->where('status', true)
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_code']);

        return response()->json([
            'success' => true,
            'period' => $range['label'],
            'period_key' => $period,
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'summary' => $summary,
            'employees' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
            ])->values(),
            'data' => collect($list->items())
                ->map(fn (Collection $collection): array => $this->formatListItem($collection))
                ->values(),
            'meta' => [
                'current_page' => $list->currentPage(),
                'last_page' => $list->lastPage(),
                'total' => $list->total(),
            ],
        ]);
    }

    public function show(Request $request, Collection $collection): JsonResponse
    {
        $this->ensureTeamCollection($request, $collection);

        $collection->load([
            'dealer:id,firm_name,owner_name,village,mobile',
            'salesEmployee:id,full_name,employee_code',
        ]);

        return response()->json([
            'data' => $this->formatDetail($collection),
        ]);
    }

    private function ensureTeamCollection(Request $request, Collection $collection): void
    {
        $reportIds = $this->access->directReportEmployeeIds($request->user());

        if (! in_array((int) $collection->sales_employee_id, $reportIds, true)) {
            abort(403, 'You can only view collections of employees reporting to you.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'employee_id' => $collection->sales_employee_id,
            'employee_name' => $collection->salesEmployee?->full_name ?? '-',
            'employee_code' => $collection->salesEmployee?->employee_code,
            'dealer_name' => $collection->dealer?->firm_name ?? '-',
            'amount' => (float) $collection->amount,
            'collection_date' => $collection->collection_date?->toDateString(),
            'status' => $collection->status,
            'status_label' => $this->statusLabel($collection->status),
            'remarks' => $collection->remarks,
            'admin_remark' => $collection->admin_remark,
            'photo_url' => $collection->photoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDetail(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'receipt_no' => $collection->receipt_no,
            'employee_id' => $collection->sales_employee_id,
            'employee_name' => $collection->salesEmployee?->full_name ?? '-',
            'employee_code' => $collection->salesEmployee?->employee_code,
            'dealer' => $collection->dealer === null ? null : [
                'id' => $collection->dealer->id,
                'firm_name' => $collection->dealer->firm_name,
                'owner_name' => $collection->dealer->owner_name,
                'village' => $collection->dealer->village,
                'mobile' => $collection->dealer->mobile,
            ],
            'dealer_name' => $collection->dealer?->firm_name ?? '-',
            'amount' => (float) $collection->amount,
            'collection_date' => $collection->collection_date?->toDateString(),
            'photo_url' => $collection->photoUrl(),
            'remarks' => $collection->remarks,
            'employee_remarks' => $collection->remarks,
            'admin_remark' => $collection->admin_remark,
            'status' => $collection->status,
            'status_label' => $this->statusLabel($collection->status),
        ];
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            Collection::STATUS_PENDING => 'Pending Verification',
            Collection::STATUS_RECEIVED => 'Received',
            Collection::STATUS_NOT_RECEIVED => 'Not Received',
            default => Collection::statusLabels()[$status] ?? (string) $status,
        };
    }
}
