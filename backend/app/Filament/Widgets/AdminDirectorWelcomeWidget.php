<?php

namespace App\Filament\Widgets;

use App\Models\Attendance;
use App\Models\DealerVisit;
use App\Models\FieldActivity;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Services\Dashboard\DashboardMetricsService;
use App\Support\AttendanceCalendar;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class AdminDirectorWelcomeWidget extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-welcome-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $tz = AttendanceCalendar::TIMEZONE;
        $now = Carbon::now($tz);
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $today = $now->toDateString();

        $metrics = app(DashboardMetricsService::class);
        $summary = $metrics->teamPerformanceSummary($monthStart, $monthEnd);

        $pendingOrders = Order::query()->where('status', 'pending_approval')->count();
        $pendingPayments = PaymentRequest::query()
            ->whereIn('status', [
                PaymentRequest::STATUS_PENDING_FIRST,
                PaymentRequest::STATUS_PENDING_SECOND,
            ])
            ->count();

        $presentToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_PRESENT)
            ->count();
        $halfDayToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_HALF_DAY)
            ->count();
        $absentToday = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->where('attendance_status', AttendanceStatusCalculator::STATUS_ABSENT)
            ->count();
        $dealerVisitsToday = DealerVisit::query()->whereDate('visit_date', $today)->count();
        $fieldVisitsToday = FieldActivity::query()->whereDate('activity_date', $today)->count();

        return [
            'userName' => $user?->employee?->full_name ?? $user?->name ?? 'User',
            'roleLabel' => $user?->adminDirectorRoleLabel() ?? 'Admin',
            'currentDate' => $now->format('l, d F Y'),
            'formatMoney' => fn (float $amount): string => Number::currency($amount, 'INR', 'en_IN'),
            'formatPercentage' => fn (float $percentage): string => number_format($percentage, 2).'%',
            'kpis' => [
                [
                    'label' => 'Sales Achievement',
                    'value' => Number::currency((float) $summary['sales_achieved'], 'INR', 'en_IN'),
                    'meta' => 'This Month · '.$this->safePct((float) $summary['sales_percentage']),
                    'tone' => 'teal',
                ],
                [
                    'label' => 'Collection Achievement',
                    'value' => Number::currency((float) $summary['collection_achieved'], 'INR', 'en_IN'),
                    'meta' => 'This Month · '.$this->safePct((float) $summary['collection_percentage']),
                    'tone' => 'blue',
                ],
                [
                    'label' => 'Pending Orders',
                    'value' => (string) $pendingOrders,
                    'meta' => $pendingOrders === 0 ? '0 Pending Orders' : 'Awaiting approval',
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Payment Approvals',
                    'value' => (string) $pendingPayments,
                    'meta' => $pendingPayments === 1 ? '1 Pending' : $pendingPayments.' Pending',
                    'tone' => 'green',
                ],
            ],
            'teamToday' => [
                ['label' => 'Present', 'value' => $presentToday],
                ['label' => 'Absent', 'value' => $absentToday],
                ['label' => 'Half Day', 'value' => $halfDayToday],
                ['label' => 'Dealer Visits', 'value' => $dealerVisitsToday],
                ['label' => 'Field Visits', 'value' => $fieldVisitsToday],
            ],
        ];
    }

    private function safePct(float $percentage): string
    {
        return number_format($percentage, 2).'%';
    }
}
