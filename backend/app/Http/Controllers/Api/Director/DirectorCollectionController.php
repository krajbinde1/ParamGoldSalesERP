<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Dealer;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Company-wide Director collection monitoring (view-only).
 */
class DirectorCollectionController extends Controller
{
    public function todayDealers(): JsonResponse
    {
        $today = AttendanceCalendar::today()->toDateString();

        $collections = Collection::query()
            ->with([
                'dealer:id,dealer_code,firm_name,village,taluka,district,assigned_employee_id',
                'dealer.assignedEmployee:id,full_name,employee_code',
                'salesEmployee:id,full_name,employee_code',
            ])
            ->whereDate('collection_date', $today)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $dealers = $collections
            ->groupBy(fn (Collection $collection): int => (int) ($collection->dealer_id ?? 0))
            ->map(fn (SupportCollection $rows): array => $this->formatDealerSummary($rows))
            ->sortByDesc('total_amount')
            ->values();

        return response()->json([
            'date' => $today,
            'total_collection' => $this->sumAmounts($collections),
            'dealers' => $dealers,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'dealer_id' => ['required', 'integer'],
        ]);

        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();
        $dealerId = (int) $validated['dealer_id'];
        $dealer = Dealer::withTrashed()
            ->with('assignedEmployee:id,full_name,employee_code')
            ->find($dealerId);

        if ($dealer === null) {
            abort(404);
        }

        $entries = Collection::query()
            ->with(['salesEmployee:id,full_name,employee_code'])
            ->where('dealer_id', $dealerId)
            ->whereDate('collection_date', $date)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'date' => $date,
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
            'photo_url' => $collection->photoUrl(),
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
