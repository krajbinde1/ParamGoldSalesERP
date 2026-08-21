<?php

namespace App\Services\Dashboard;

use App\Models\Attendance;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\Order;
use App\Models\Product;
use App\Models\TaDaClaim;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;

class AdminDashboardDataService
{
    /**
     * Team Today counts for the Admin/Director dashboard.
     * Punched In is unique employees with a punch-in time today — not attendance_status.
     *
     * @return array{
     *     today: string,
     *     punched_in: int,
     *     present: int,
     *     absent: int,
     *     half_day: int,
     *     dealer_visits: int,
     *     field_visits: int
     * }
     */
    public function teamTodayCounts(): array
    {
        $today = AttendanceCalendar::today()->toDateString();

        $attendance = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->toBase()
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN punch_in_time IS NOT NULL THEN employee_id END) as punched_in,
                 SUM(CASE WHEN attendance_status = ? THEN 1 ELSE 0 END) as present,
                 SUM(CASE WHEN attendance_status = ? THEN 1 ELSE 0 END) as absent,
                 SUM(CASE WHEN attendance_status = ? THEN 1 ELSE 0 END) as half_day',
                [
                    AttendanceStatusCalculator::STATUS_PRESENT,
                    AttendanceStatusCalculator::STATUS_ABSENT,
                    AttendanceStatusCalculator::STATUS_HALF_DAY,
                ],
            )
            ->first();

        return [
            'today' => $today,
            'punched_in' => (int) ($attendance->punched_in ?? 0),
            'present' => (int) ($attendance->present ?? 0),
            'absent' => (int) ($attendance->absent ?? 0),
            'half_day' => (int) ($attendance->half_day ?? 0),
            'dealer_visits' => DealerVisit::query()->whereDate('visit_date', $today)->count(),
            'field_visits' => FieldActivity::query()->whereDate('activity_date', $today)->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function businessSummaryCounts(): array
    {
        return [
            'total_employees' => Employee::query()->count(),
            'active_employees' => Employee::query()->where('status', true)->count(),
            'total_dealers' => Dealer::query()->count(),
            'total_products' => Product::query()->count(),
            'total_orders' => Order::query()->count(),
            'pending_orders' => Order::query()->where('status', 'pending_approval')->count(),
            'approved_orders' => Order::query()->where('status', 'approved')->count(),
            'dispatched_orders' => Order::query()->where('status', Order::STATUS_DISPATCHED)->count(),
            'total_collections' => Collection::query()->count(),
            'total_collection_amount' => round((float) Collection::query()->sum('amount'), 2),
            'pending_ta_da_claims' => TaDaClaim::query()->where('status', TaDaClaim::STATUS_PENDING)->count(),
        ];
    }
}
