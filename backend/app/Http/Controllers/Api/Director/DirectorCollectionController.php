<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Employee;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Company-wide Director collection monitoring (view-only).
 */
class DirectorCollectionController extends Controller
{
    public function todayDealers(Request $request): JsonResponse
    {
        return $this->dealerSummaries($request);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateFilters($request, dealerRequired: false);
        if (filled($validated['dealer_id'] ?? null)) {
            return $this->dealerEntries($request, (int) $validated['dealer_id']);
        }

        return $this->dealerSummaries($request);
    }

    public function show(Collection $collection): JsonResponse
    {
        $collection->load([
            'dealer:id,firm_name,owner_name,village,mobile',
            'salesEmployee:id,full_name,employee_code',
        ]);

        return response()->json([
            'data' => array_merge($this->formatEntry($collection), [
                'receipt_no' => $collection->receipt_no,
                'dealer' => $collection->dealer === null ? null : [
                    'id' => $collection->dealer->id,
                    'firm_name' => $collection->dealer->firm_name,
                    'owner_name' => $collection->dealer->owner_name,
                    'village' => $collection->dealer->village,
                    'mobile' => $collection->dealer->mobile,
                ],
                'dealer_name' => $collection->dealer?->firm_name ?? '-',
                'employee_remarks' => $collection->remarks,
                'admin_remark' => $collection->admin_remark,
            ]),
        ]);
    }

    private function dealerSummaries(Request $request): JsonResponse
    {
        $filters = $this->resolveFilters($this->validateFilters($request, dealerRequired: false));

        $collections = $this->filteredQuery($filters)
            ->with([
                'dealer:id,dealer_code,firm_name,village,taluka,district,assigned_employee_id',
                'dealer.assignedEmployee:id,full_name,employee_code',
                'salesEmployee:id,full_name,employee_code',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $dealers = $collections
            ->groupBy(fn (Collection $collection): int => (int) ($collection->dealer_id ?? 0))
            ->map(fn (SupportCollection $rows): array => $this->formatDealerSummary($rows))
            ->sortByDesc('total_amount')
            ->values();

        return response()->json([
            'date' => $filters['date_from'] === $filters['date_to'] ? $filters['date_from'] : null,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'period' => $filters['period'],
            'employee_id' => $filters['employee_id'],
            'total_collection' => $this->sumAmounts($collections),
            'dealers_count' => $dealers->count(),
            'entries_count' => $collections->count(),
            'dealers' => $dealers,
            'employees' => $this->employeesForRange($filters['date_from'], $filters['date_to']),
        ]);
    }

    private function dealerEntries(Request $request, int $dealerId): JsonResponse
    {
        $filters = $this->resolveFilters($this->validateFilters($request, dealerRequired: false));
        $dealer = Dealer::withTrashed()
            ->with('assignedEmployee:id,full_name,employee_code')
            ->find($dealerId);

        if ($dealer === null) {
            abort(404);
        }

        $entries = $this->filteredQuery($filters)
            ->with(['salesEmployee:id,full_name,employee_code'])
            ->where('dealer_id', $dealerId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'date' => $filters['date_from'] === $filters['date_to'] ? $filters['date_from'] : null,
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'period' => $filters['period'],
            'employee_id' => $filters['employee_id'],
            'dealer' => [
                'dealer_id' => $dealer->id,
                'dealer_code' => $dealer->dealer_code,
                'dealer_name' => $dealer->firm_name,
                'village' => $dealer->village,
                'taluka' => $dealer->taluka,
                'district' => $dealer->district,
                'assigned_employee_name' => $dealer->assignedEmployee?->full_name,
            ],
            'total_amount' => $this->sumAmounts($entries),
            'entries_count' => $entries->count(),
            'data' => $entries
                ->map(fn (Collection $collection): array => $this->formatEntry($collection))
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, bool $dealerRequired): array
    {
        return $request->validate([
            'period' => ['nullable', 'in:today,week,month,custom'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'employee_id' => ['nullable', 'integer'],
            'dealer_id' => [$dealerRequired ? 'required' : 'nullable', 'integer'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{period: string, date_from: string, date_to: string, employee_id: int|null}
     */
    private function resolveFilters(array $validated): array
    {
        $today = AttendanceCalendar::today();
        $period = $validated['period'] ?? null;
        $from = $validated['date_from'] ?? null;
        $to = $validated['date_to'] ?? null;
        $single = $validated['date'] ?? null;

        if ($from !== null || $to !== null) {
            $period = $period ?: 'custom';
            $from ??= $to;
            $to ??= $from;
        } elseif ($period === 'week') {
            $from = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $to = $today->copy()->endOfWeek(Carbon::MONDAY)->toDateString();
        } elseif ($period === 'month') {
            $from = $today->copy()->startOfMonth()->toDateString();
            $to = $today->toDateString();
        } elseif (filled($single)) {
            $period = $period ?: 'custom';
            $from = $single;
            $to = $single;
        } else {
            $period = 'today';
            $from = $today->toDateString();
            $to = $today->toDateString();
        }

        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        if ($employeeId !== null && $employeeId <= 0) {
            $employeeId = null;
        }

        return [
            'period' => $period,
            'date_from' => $from,
            'date_to' => $to,
            'employee_id' => $employeeId,
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, employee_id: int|null}  $filters
     * @return \Illuminate\Database\Eloquent\Builder<Collection>
     */
    private function filteredQuery(array $filters)
    {
        return Collection::query()
            ->whereDate('collection_date', '>=', $filters['date_from'])
            ->whereDate('collection_date', '<=', $filters['date_to'])
            ->when(
                $filters['employee_id'] !== null,
                fn ($query) => $query->where('sales_employee_id', $filters['employee_id']),
            );
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null}>
     */
    private function employeesForRange(string $from, string $to): array
    {
        $ids = Collection::query()
            ->whereDate('collection_date', '>=', $from)
            ->whereDate('collection_date', '<=', $to)
            ->whereNotNull('sales_employee_id')
            ->distinct()
            ->pluck('sales_employee_id');

        return Employee::query()
            ->whereIn('id', $ids)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  SupportCollection<int, Collection>  $rows
     * @return array<string, mixed>
     */
    private function formatDealerSummary(SupportCollection $rows): array
    {
        /** @var Collection $first */
        $first = $rows->first();
        $dealer = $first->dealer;
        $employeeNames = $rows
            ->map(fn (Collection $collection): ?string => $collection->salesEmployee?->full_name)
            ->filter()
            ->unique()
            ->values();

        $locationParts = array_values(array_filter([
            $dealer?->village,
            $dealer?->taluka,
            $dealer?->district,
        ]));

        return [
            'dealer_id' => $dealer?->id ?? $first->dealer_id,
            'dealer_code' => $dealer?->dealer_code,
            'dealer_name' => $dealer?->firm_name ?? '-',
            'village' => $dealer?->village,
            'taluka' => $dealer?->taluka,
            'district' => $dealer?->district,
            'location' => $locationParts !== [] ? implode(', ', $locationParts) : null,
            'assigned_employee_name' => $dealer?->assignedEmployee?->full_name,
            'employee_name' => $employeeNames->isNotEmpty()
                ? $employeeNames->implode(', ')
                : ($dealer?->assignedEmployee?->full_name ?? '-'),
            'total_amount' => $this->sumAmounts($rows),
            'entries_count' => $rows->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(Collection $collection): array
    {
        $collectedAt = $collection->created_at
            ?->copy()
            ->timezone(AttendanceCalendar::TIMEZONE);
        $photoUrl = $collection->photoUrl();

        return [
            'id' => $collection->id,
            'amount' => (float) $collection->amount,
            'collection_date' => $collection->collection_date?->toDateString(),
            'collected_at' => $collectedAt?->toIso8601String(),
            'collection_time' => $collectedAt?->format('H:i:s'),
            'employee_id' => $collection->sales_employee_id,
            'employee_name' => $collection->salesEmployee?->full_name ?? '-',
            'employee_code' => $collection->salesEmployee?->employee_code,
            'payment_mode' => $collection->payment_mode,
            'remarks' => $collection->remarks,
            'status' => $collection->status,
            'status_label' => Collection::statusLabels()[$collection->status] ?? (string) $collection->status,
            'photo_url' => $photoUrl,
            'supporting_image_url' => $photoUrl,
        ];
    }

    /**
     * @param  SupportCollection<int, Collection>  $rows
     */
    private function sumAmounts(SupportCollection $rows): float
    {
        return (float) $rows->sum(fn (Collection $collection): float => (float) $collection->amount);
    }
}
