<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Services\Orders\ManagerOrderAccessService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ManagerTeamActivityController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(['all', 'dealer_visit', 'field_visit'])],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();
        $type = $validated['type'] ?? 'all';

        if ($reportIds === []) {
            return response()->json([
                'data' => [],
                'meta' => $this->emptyMeta($date),
            ]);
        }

        $employees = Employee::query()
            ->whereIn('id', $reportIds)
            ->where('status', true)
            ->when(filled($validated['search'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['search'].'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term);
                });
            })
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code']);

        $employeeIds = $employees->pluck('id')->all();

        $dealerVisits = DealerVisit::query()
            ->with('dealer:id,firm_name,dealer_code')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('visit_date', $date)
            ->orderByDesc('visit_time')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        $fieldVisits = FieldActivity::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('activity_date', $date)
            ->orderByDesc('activity_time')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($dealerVisits, $fieldVisits): array {
            $visits = $dealerVisits->get($employee->id) ?? collect();
            $activities = $fieldVisits->get($employee->id) ?? collect();
            $last = $this->resolveLastActivity($visits, $activities);

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'dealer_visit_count' => $visits->count(),
                'field_visit_count' => $activities->count(),
                'total_activity_count' => $visits->count() + $activities->count(),
                'last_activity_type' => $last['type'],
                'last_activity_type_label' => $last['type_label'],
                'last_activity_time' => $last['time'],
                'last_activity_at' => $last['at'],
            ];
        });

        $totalDealerVisits = (int) $rows->sum('dealer_visit_count');
        $totalFieldVisits = (int) $rows->sum('field_visit_count');
        $activeEmployees = $rows
            ->filter(fn (array $row): bool => $row['total_activity_count'] > 0)
            ->count();

        if ($type === 'dealer_visit') {
            $rows = $rows->filter(fn (array $row): bool => $row['dealer_visit_count'] > 0);
        } elseif ($type === 'field_visit') {
            $rows = $rows->filter(fn (array $row): bool => $row['field_visit_count'] > 0);
        } else {
            // Monitoring list: only employees with Dealer/Field activity on the date.
            $rows = $rows->filter(fn (array $row): bool => $row['total_activity_count'] > 0);
        }

        $rows = $rows->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $date,
                'type' => $type,
                'total_dealer_visits' => $totalDealerVisits,
                'total_field_visits' => $totalFieldVisits,
                'active_employees' => $activeEmployees,
                'total_employees' => $employees->count(),
            ],
        ]);
    }

    public function employeeTimeline(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureTeamEmployee($request, $employee);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'type' => ['nullable', 'string', Rule::in(['all', 'dealer_visit', 'field_visit'])],
        ]);

        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();
        $type = $validated['type'] ?? 'all';

        $timeline = collect();

        if ($type === 'all' || $type === 'dealer_visit') {
            $dealerVisits = DealerVisit::query()
                ->with('dealer:id,firm_name,dealer_code,owner_name,village,taluka,district,address')
                ->where('employee_id', $employee->id)
                ->whereDate('visit_date', $date)
                ->get();

            foreach ($dealerVisits as $visit) {
                $timeline->push($this->formatDealerVisitTimelineItem($visit, $employee));
            }
        }

        if ($type === 'all' || $type === 'field_visit') {
            $fieldVisits = FieldActivity::query()
                ->with(['crop', 'recommendations.product'])
                ->where('employee_id', $employee->id)
                ->whereDate('activity_date', $date)
                ->get();

            foreach ($fieldVisits as $activity) {
                $timeline->push($this->formatFieldVisitTimelineItem($activity, $employee));
            }
        }

        $sorted = $timeline
            ->sortByDesc(fn (array $item): string => $item['sort_key'])
            ->values()
            ->map(function (array $item): array {
                unset($item['sort_key']);

                return $item;
            });

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
            ],
            'meta' => [
                'date' => $date,
                'type' => $type,
                'dealer_visit_count' => $sorted->where('type', 'dealer_visit')->count(),
                'field_visit_count' => $sorted->where('type', 'field_visit')->count(),
                'total_activity_count' => $sorted->count(),
            ],
            'data' => $sorted,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DealerVisit>  $visits
     * @param  \Illuminate\Support\Collection<int, FieldActivity>  $activities
     * @return array{type: ?string, type_label: ?string, time: ?string, at: ?string}
     */
    private function resolveLastActivity($visits, $activities): array
    {
        $candidates = collect();

        foreach ($visits as $visit) {
            $time = $this->formatClockTime($visit->visit_time);
            $at = $this->combineDateAndTime($visit->visit_date?->toDateString(), $visit->visit_time);
            $candidates->push([
                'type' => 'dealer_visit',
                'type_label' => 'Dealer Visit',
                'time' => $time,
                'at' => $at,
                'sort_key' => $at ?? '',
            ]);
        }

        foreach ($activities as $activity) {
            $time = $this->formatFieldActivityTime($activity);
            $at = $this->combineDateAndTime(
                $activity->activity_date?->toDateString(),
                $activity->activity_time ?: $activity->created_at?->timezone(AttendanceCalendar::TIMEZONE)->format('H:i:s'),
            );
            $candidates->push([
                'type' => 'field_visit',
                'type_label' => 'Field Activity',
                'time' => $time,
                'at' => $at,
                'sort_key' => $at ?? '',
            ]);
        }

        $last = $candidates->sortByDesc('sort_key')->first();

        if ($last === null) {
            return [
                'type' => null,
                'type_label' => null,
                'time' => null,
                'at' => null,
            ];
        }

        return [
            'type' => $last['type'],
            'type_label' => $last['type_label'],
            'time' => $last['time'],
            'at' => $last['at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDealerVisitTimelineItem(DealerVisit $visit, Employee $employee): array
    {
        $dealer = $visit->dealer;
        $locationParts = array_filter([
            $dealer?->village,
            $dealer?->taluka,
            $dealer?->district,
        ]);

        $time = $this->formatClockTime($visit->visit_time);
        $at = $this->combineDateAndTime($visit->visit_date?->toDateString(), $visit->visit_time);
        $latitude = $visit->latitude !== null ? (float) $visit->latitude : null;
        $longitude = $visit->longitude !== null ? (float) $visit->longitude : null;

        return [
            'id' => $visit->id,
            'type' => 'dealer_visit',
            'type_label' => 'Dealer Visit',
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'activity_date' => $visit->visit_date?->toDateString(),
            'activity_time' => $time,
            'occurred_at' => $at,
            'sort_key' => $at ?? sprintf('%s-%d', $visit->visit_date?->toDateString() ?? '', $visit->id),
            'status' => $visit->status,
            'status_label' => DealerVisit::statusLabel($visit->status),
            'photo_url' => $visit->photoUrl(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $visit->accuracy !== null ? (float) $visit->accuracy : null,
            'maps_url' => $visit->mapsUrl(),
            'location_available' => $latitude !== null && $longitude !== null,
            'location' => $locationParts !== [] ? implode(', ', $locationParts) : ($dealer?->address),
            'remark' => null,
            'dealer' => [
                'id' => $dealer?->id,
                'name' => $dealer?->firm_name,
                'code' => $dealer?->dealer_code,
                'owner_name' => $dealer?->owner_name,
                'village' => $dealer?->village,
                'taluka' => $dealer?->taluka,
                'district' => $dealer?->district,
                'address' => $dealer?->address,
            ],
            'field' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFieldVisitTimelineItem(FieldActivity $activity, Employee $employee): array
    {
        $time = $this->formatFieldActivityTime($activity);
        $rawTime = filled($activity->activity_time)
            ? (string) $activity->activity_time
            : $activity->created_at?->timezone(AttendanceCalendar::TIMEZONE)->format('H:i:s');
        $at = $this->combineDateAndTime($activity->activity_date?->toDateString(), $rawTime);
        $locationParts = array_filter([
            $activity->village,
            $activity->taluka,
            $activity->district,
        ]);
        $latitude = $activity->latitude !== null ? (float) $activity->latitude : null;
        $longitude = $activity->longitude !== null ? (float) $activity->longitude : null;
        $description = trim(implode(', ', array_filter([
            $activity->farmer_name,
            $activity->village,
            $activity->taluka,
            $activity->district,
        ])));
        $recommendations = $activity->recommendations
            ->map(fn ($row): array => $row->toApiArray())
            ->values()
            ->all();

        return [
            'id' => $activity->id,
            'type' => 'field_visit',
            'type_label' => 'Field Activity',
            'employee_id' => $employee->id,
            'employee_name' => $employee->full_name,
            'activity_date' => $activity->activity_date?->toDateString(),
            'activity_time' => $time,
            'occurred_at' => $at,
            'sort_key' => $at ?? sprintf('%s-%d', $activity->activity_date?->toDateString() ?? '', $activity->id),
            'status' => $activity->status,
            'status_label' => FieldActivity::statusLabel($activity->status),
            'photo_url' => $activity->photoUrl(),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => null,
            'maps_url' => $activity->mapsUrl(),
            'location_available' => $latitude !== null && $longitude !== null,
            'location' => $locationParts !== [] ? implode(', ', $locationParts) : null,
            'remark' => $activity->remark ?: ($description !== '' ? $description : null),
            'dealer' => null,
            'field' => [
                'farmer_name' => $activity->farmer_name,
                'farmer_mobile' => $activity->farmer_mobile,
                'village' => $activity->village,
                'taluka' => $activity->taluka,
                'district' => $activity->district,
                'crop_name' => $activity->crop?->name,
                'recommendations' => $recommendations,
            ],
        ];
    }

    private function ensureTeamEmployee(Request $request, Employee $employee): void
    {
        $reportIds = $this->access->directReportEmployeeIds($request->user());

        if (! in_array((int) $employee->id, $reportIds, true)) {
            abort(403, 'You can only view activity of employees reporting to you.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyMeta(string $date): array
    {
        return [
            'date' => $date,
            'type' => 'all',
            'total_dealer_visits' => 0,
            'total_field_visits' => 0,
            'active_employees' => 0,
            'total_employees' => 0,
        ];
    }

    private function formatClockTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }

        return Carbon::parse((string) $value)->format('H:i');
    }

    private function formatFieldActivityTime(FieldActivity $activity): ?string
    {
        if (filled($activity->activity_time)) {
            return Carbon::parse((string) $activity->activity_time)->format('H:i');
        }

        return $activity->created_at
            ?->timezone(AttendanceCalendar::TIMEZONE)
            ->format('H:i');
    }

    private function combineDateAndTime(?string $date, mixed $time): ?string
    {
        if ($date === null || $time === null || $time === '') {
            return null;
        }

        $timeString = $time instanceof Carbon
            ? $time->format('H:i:s')
            : (string) $time;

        if (strlen($timeString) === 5) {
            $timeString .= ':00';
        }

        try {
            return Carbon::parse($date.' '.$timeString, AttendanceCalendar::TIMEZONE)
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
