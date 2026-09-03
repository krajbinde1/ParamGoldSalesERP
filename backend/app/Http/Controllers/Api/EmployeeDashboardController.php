<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeTask;
use App\Models\Order;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        $context = $this->periodContext($request);

        if ($employee === null) {
            return response()->json($this->payload(
                employee: null,
                attendance: null,
                targets: $this->zeroTargets(),
                context: $context,
            ));
        }

        $targets = $this->metrics->targetSummaryForPeriod(
            $employee->id,
            $context['start'],
            $context['end'],
            $context['period'],
        );

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        return response()->json($this->payload(
            employee: $employee,
            attendance: $attendance,
            targets: $targets,
            context: $context,
        ));
    }

    public function targets(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        $context = $this->periodContext($request);

        $targets = $employee === null
            ? $this->zeroTargets()
            : $this->metrics->targetSummaryForPeriod(
                $employee->id,
                $context['start'],
                $context['end'],
                $context['period'],
            );

        return response()->json([
            'success' => true,
            ...$this->periodMeta($context),
            ...$this->targetFields($targets),
        ]);
    }

    /**
     * @return array{period: string, label: string, start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon, start_date: string, end_date: string}
     */
    private function periodContext(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', DashboardMetricsService::periodValidationRule()],
            'start_date' => ['nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
        ]);

        return $this->metrics->periodContext(
            $validated['period'] ?? null,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $targets
     * @param  array{period: string, label: string, start: \Illuminate\Support\Carbon, end: \Illuminate\Support\Carbon, start_date: string, end_date: string}  $context
     */
    private function payload(?Employee $employee, ?Attendance $attendance, array $targets, array $context): array
    {
        $fields = $this->targetFields($targets);

        return [
            'success' => true,
            ...$this->periodMeta($context),
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'designation' => $employee->designation,
                'base_location' => $employee->base_location,
                'profile_photo_url' => $employee->profile_photo_path
                    ? asset('storage/'.$employee->profile_photo_path)
                    : null,
            ],
            'today_attendance' => [
                'status' => strtolower($attendance?->attendance_status ?? 'absent'),
                'punch_in' => $attendance?->punch_in_time,
                'punch_out' => $attendance?->punch_out_time,
                'working_hours' => $attendance?->working_hours,
            ],
            ...$fields,
            'today_dealer_visits' => 0,
            'today_field_activities' => 0,
            'today_planning' => [
                'pending' => $employee === null ? 0 : EmployeeTask::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('due_date', today())
                    ->where('is_completed', false)
                    ->count(),
                'completed' => $employee === null ? 0 : EmployeeTask::query()
                    ->where('employee_id', $employee->id)
                    ->where('is_completed', true)
                    ->whereDate('completed_at', today())
                    ->count(),
            ],
            'summary' => [
                'today_orders' => $employee === null ? 0 : Order::query()
                    ->where('sales_employee_id', $employee->id)
                    ->whereDate('order_date', today())
                    ->count(),
                'pending_ta_da_claims' => 0,
                'today_field_activities' => 0,
                'today_dealer_visits' => 0,
                ...$fields,
            ],
            'permissions' => [
                'attendance' => true,
                'book_order' => true,
                'ta_da_claim' => true,
                'field_activity' => true,
                'dealer_visit' => true,
            ],
        ];
    }

    /**
     * @param  array{period: string, label: string, start_date: string, end_date: string}  $context
     * @return array<string, mixed>
     */
    private function periodMeta(array $context): array
    {
        return [
            'period' => $context['label'],
            'period_key' => $context['period'],
            'start_date' => $context['start_date'],
            'end_date' => $context['end_date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $targets
     * @return array<string, mixed>
     */
    private function targetFields(array $targets): array
    {
        $salesTarget = (float) ($targets['sales_target'] ?? 0);
        $salesAchieved = (float) ($targets['sales_achieved'] ?? 0);
        $salesPercentage = $targets['sales_percentage'] ?? null;
        $salesRemaining = (float) ($targets['sales_remaining'] ?? max($salesTarget - $salesAchieved, 0));
        $collectionTarget = (float) ($targets['collection_target'] ?? 0);
        $collectionAchieved = (float) ($targets['collection_achieved'] ?? 0);
        $collectionPercentage = $targets['collection_percentage'] ?? null;
        $collectionRemaining = (float) ($targets['collection_remaining'] ?? max($collectionTarget - $collectionAchieved, 0));
        $fieldActivityTarget = (int) ($targets['field_activity_target'] ?? 0);
        $fieldActivityAchieved = (int) ($targets['field_activity_achieved'] ?? 0);
        $fieldActivityRemaining = (int) ($targets['field_activity_remaining'] ?? max($fieldActivityTarget - $fieldActivityAchieved, 0));
        $fieldActivityPercentage = $targets['field_activity_percentage'] ?? null;

        $fieldActivity = [
            'field_activity_target' => $fieldActivityTarget,
            'field_activity_achieved' => $fieldActivityAchieved,
            'field_activity_remaining' => $fieldActivityRemaining,
            'field_activity_percentage' => $fieldActivityPercentage,
            'weekly_field_activity_target' => $fieldActivityTarget,
            'weekly_field_activity_achieved' => $fieldActivityAchieved,
            'weekly_field_activity_remaining' => $fieldActivityRemaining,
            'weekly_field_activity_percentage' => $fieldActivityPercentage,
        ];

        return [
            'sales_target' => $salesTarget,
            'sales_achieved' => $salesAchieved,
            'sales_percentage' => $salesPercentage,
            'sales_remaining' => $salesRemaining,
            'collection_target' => $collectionTarget,
            'collection_achieved' => $collectionAchieved,
            'collection_percentage' => $collectionPercentage,
            'collection_remaining' => $collectionRemaining,
            ...$fieldActivity,
            'weekly_sales_target' => $salesTarget,
            'weekly_sales_achieved' => $salesAchieved,
            'weekly_sales_percentage' => $salesPercentage,
            'weekly_collection_target' => $collectionTarget,
            'weekly_collection_achieved' => $collectionAchieved,
            'weekly_collection_percentage' => $collectionPercentage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroTargets(): array
    {
        return [
            'sales_target' => 0.0,
            'sales_achieved' => 0.0,
            'sales_percentage' => null,
            'sales_remaining' => 0.0,
            'collection_target' => 0.0,
            'collection_achieved' => 0.0,
            'collection_percentage' => null,
            'collection_remaining' => 0.0,
            'field_activity_target' => 0,
            'field_activity_achieved' => 0,
            'field_activity_remaining' => 0,
            'field_activity_percentage' => null,
        ];
    }
}
