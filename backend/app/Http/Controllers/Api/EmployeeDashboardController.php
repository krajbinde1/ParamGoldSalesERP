<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Order;
use App\Models\WeeklyTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        $weeklyTarget = WeeklyTarget::activeForEmployee($employee->id);

        $weeklySalesTarget = 0.0;
        $weeklySalesAchieved = 0.0;
        $weeklyCollectionTarget = 0.0;
        $weeklyCollectionAchieved = 0.0;

        if ($weeklyTarget !== null) {
            $weeklySalesTarget = (float) $weeklyTarget->sales_target;
            $weeklySalesAchieved = round($weeklyTarget->salesAchieved($employee->id), 2);
            $weeklyCollectionTarget = (float) $weeklyTarget->collection_target;
            $weeklyCollectionAchieved = round($weeklyTarget->collectionAchieved($employee->id), 2);
        }

        $weeklySalesPercentage = $this->achievementPercentage(
            $weeklySalesTarget,
            $weeklySalesAchieved,
        );
        $weeklyCollectionPercentage = $this->achievementPercentage(
            $weeklyCollectionTarget,
            $weeklyCollectionAchieved,
        );

        return response()->json([
            'success' => true,
            'employee' => [
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
            'weekly_sales_target' => $weeklySalesTarget,
            'weekly_sales_achieved' => $weeklySalesAchieved,
            'weekly_sales_percentage' => $weeklySalesPercentage,
            'weekly_collection_target' => $weeklyCollectionTarget,
            'weekly_collection_achieved' => $weeklyCollectionAchieved,
            'weekly_collection_percentage' => $weeklyCollectionPercentage,
            'today_dealer_visits' => 0,
            'today_field_activities' => 0,
            'summary' => [
                'today_orders' => Order::query()
                    ->where('sales_employee_id', $employee->id)
                    ->whereDate('order_date', today())
                    ->count(),
                'pending_ta_da_claims' => 0,
                'today_field_activities' => 0,
                'today_dealer_visits' => 0,
                'weekly_sales_target' => $weeklySalesTarget,
                'weekly_sales_achieved' => $weeklySalesAchieved,
                'weekly_sales_percentage' => $weeklySalesPercentage,
                'weekly_collection_target' => $weeklyCollectionTarget,
                'weekly_collection_achieved' => $weeklyCollectionAchieved,
                'weekly_collection_percentage' => $weeklyCollectionPercentage,
            ],
            'permissions' => [
                'attendance' => true,
                'book_order' => true,
                'ta_da_claim' => true,
                'field_activity' => true,
                'dealer_visit' => true,
            ],
        ]);
    }

    private function achievementPercentage(float $target, float $achieved): float
    {
        return $target > 0 ? round(($achieved / $target) * 100, 2) : 0.0;
    }
}
