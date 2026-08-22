<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Api\EmployeeFieldActivityController;
use App\Http\Controllers\Controller;
use App\Models\FieldActivity;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Company-wide Director field visit monitoring (view-only).
 */
class DirectorFieldVisitController extends Controller
{
    public function today(): JsonResponse
    {
        $today = AttendanceCalendar::today()->toDateString();

        $visits = FieldActivity::query()
            ->with([
                'employee:id,full_name,employee_code',
                'crop:id,name',
                'recommendations.product:id,product_name,product_code',
            ])
            ->whereDate('activity_date', $today)
            ->orderByDesc('activity_time')
            ->orderByDesc('id')
            ->get();

        $employees = $visits
            ->groupBy(fn (FieldActivity $visit): int => (int) ($visit->employee_id ?? 0))
            ->map(fn (SupportCollection $rows): array => $this->formatEmployeeGroup($rows))
            ->sortBy([
                ['visits_count', 'desc'],
                ['employee_name', 'asc'],
            ])
            ->values();

        return response()->json([
            'date' => $today,
            'total_visits' => $visits->count(),
            'employees_visited' => $employees->count(),
            'employees' => $employees,
        ]);
    }

    public function show(FieldActivity $fieldActivity): JsonResponse
    {
        $fieldActivity->load([
            'employee:id,full_name,employee_code',
            'crop',
            'farmer.district',
            'farmer.taluka',
            'recommendations.product',
            'recommendations.crop',
        ]);

        $data = app(EmployeeFieldActivityController::class)->formatDetail($fieldActivity);
        $data['employee_code'] = $fieldActivity->employee?->employee_code;

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * @param  SupportCollection<int, FieldActivity>  $rows
     * @return array<string, mixed>
     */
    private function formatEmployeeGroup(SupportCollection $rows): array
    {
        /** @var FieldActivity $first */
        $first = $rows->first();
        $employee = $first->employee;

        return [
            'employee_id' => $employee?->id ?? $first->employee_id,
            'employee_name' => $employee?->full_name ?? '-',
            'employee_code' => $employee?->employee_code,
            'visits_count' => $rows->count(),
            'visits' => $rows
                ->map(fn (FieldActivity $visit): array => $this->formatListItem($visit))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListItem(FieldActivity $activity): array
    {
        $recommendation = $activity->recommendations
            ->map(fn ($row): ?string => $row->product?->product_name)
            ->filter()
            ->unique()
            ->implode(', ');

        return [
            'id' => $activity->id,
            'employee_name' => $activity->employee?->full_name,
            'employee_code' => $activity->employee?->employee_code,
            'farmer_name' => $activity->farmer_name,
            'farmer_mobile' => $activity->farmer_mobile,
            'village' => $activity->village,
            'taluka' => $activity->taluka,
            'district' => $activity->district,
            'crop_name' => $activity->crop?->name,
            'product_recommendation' => $recommendation !== '' ? $recommendation : null,
            'activity_date' => $activity->activity_date?->toDateString(),
            'activity_time' => $this->formatActivityTime($activity),
            'remark' => $activity->remark,
            'photo_url' => $activity->photoUrl(),
            'latitude' => $activity->latitude !== null ? (float) $activity->latitude : null,
            'longitude' => $activity->longitude !== null ? (float) $activity->longitude : null,
            'maps_url' => $activity->mapsUrl(),
            'location_available' => $activity->latitude !== null && $activity->longitude !== null,
        ];
    }

    private function formatActivityTime(FieldActivity $activity): string
    {
        if (filled($activity->activity_time)) {
            return Carbon::parse((string) $activity->activity_time)->format('H:i');
        }

        return $activity->created_at
            ?->timezone(AttendanceCalendar::TIMEZONE)
            ->format('H:i') ?? '-';
    }
}
