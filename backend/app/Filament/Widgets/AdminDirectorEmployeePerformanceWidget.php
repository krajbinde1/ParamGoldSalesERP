<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Filament\Pages\ManagerEmployeePerformanceDetail;
use App\Filament\Pages\TeamPerformance;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DirectorDashboardDataService;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AdminDirectorEmployeePerformanceWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-employee-performance-widget';

    public static function canView(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareMessage(array $row, string $monthLabel): string
    {
        return TeamPerformance::whatsappShareMessage($row, $monthLabel);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareUrl(array $row, string $monthLabel): string
    {
        return TeamPerformance::whatsappShareUrl($row, $monthLabel);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $now = Carbon::now('Asia/Kolkata');
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $monthLabel = $start->format('F Y');

        $employees = collect(app(DashboardMetricsService::class)->employeePerformance(
            $start,
            $end,
            role: UserRole::Employee->value,
        ))
            ->sortBy([
                ['overall_percentage', 'desc'],
                ['employee_name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'employees' => $employees,
            'monthLabel' => $monthLabel,
            'detailUrl' => fn (int $employeeId): string => ManagerEmployeePerformanceDetail::getUrl([
                'employee' => $employeeId,
            ]),
            'formatMoney' => fn (float $amount): string => DirectorDashboardDataService::formatCompact($amount),
            'formatPct' => fn (float $percentage): string => number_format($percentage, 0).'%',
            'barWidth' => fn (float $percentage): float => min(max($percentage, 0), 100),
            'whatsappUrl' => fn (array $row): string => self::whatsappShareUrl($row, $monthLabel),
        ];
    }
}
