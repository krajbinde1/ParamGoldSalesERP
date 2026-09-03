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

        if ($employee === null) {
            return response()->json($this->payload(
                employee: null,
                attendance: null,
                targets: $this->zeroTargets(),
            ));
        }

        $range = $this->metrics->resolveDateRange('month');
        $targets = $this->metrics->targetSummaryForPeriod(
            $employee->id,
            $range['start'],
            $range['end'],
            'month',
        );

        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        return response()->json($this->payload(
            employee: $employee,
            attendance: $attendance,
            targets: $targets,
        ));
    }

    /**
     * @param  array<string, mixed>  $targets
     */
    private function payload(?Employee $employee, ?Attendance $attendance, array $targets): array
    {
        $salesTarget = (float) ($targets['sales_target'] ?? 0);
        $salesAchieved = (float) ($targets['sales_achieved'] ?? 0);
        $salesPercentage = (float) ($targets['sales_percentage'] ?? 0);
        $salesRemaining = (float) ($targets['sales_remaining'] ?? max($salesTarget - $salesAchieved, 0));
        $collectionTarget = (float) ($targets['collection_target'] ?? 0);
        $collectionAchieved = (float) ($targets['collection_achieved'] ?? 0);
        $collectionPercentage = (float) ($targets['collection_percentage'] ?? 0);
        $collectionRemaining = (float) ($targets['collection_remaining'] ?? max($collectionTarget - $collectionAchieved, 0));
        $fieldActivityTarget = (int) ($targets['field_activity_target'] ?? 0);
        $fieldActivityAchieved = (int) ($targets['field_activity_achieved'] ?? 0);
        $fieldActivityRemaining = (int) ($targets['field_activity_remaining'] ?? max($fieldActivityTarget - $fieldActivityAchieved, 0));
        $fieldActivityPercentage = (float) ($targets['field_activity_percentage'] ?? 0);

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
            'success' => true,
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
     * @return array<string, float>
     */
    private function zeroTargets(): array
    {
        return [
            'sales_target' => 0.0,
            'sales_achieved' => 0.0,
            'sales_percentage' => 0.0,
            'sales_remaining' => 0.0,
            'collection_target' => 0.0,
            'collection_achieved' => 0.0,
            'collection_percentage' => 0.0,
            'collection_remaining' => 0.0,
            'field_activity_target' => 0,
            'field_activity_achieved' => 0,
            'field_activity_remaining' => 0,
            'field_activity_percentage' => 0.0,
        ];
    }
}
