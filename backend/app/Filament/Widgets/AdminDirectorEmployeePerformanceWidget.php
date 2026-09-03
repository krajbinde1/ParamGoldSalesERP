<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Filament\Pages\ManagerEmployeePerformanceDetail;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Support\IndianCurrency;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class AdminDirectorEmployeePerformanceWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-employee-performance-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareMessage(array $row, string $monthLabel): string
    {
        $salesPct = number_format((float) ($row['sales_percentage'] ?? 0), 1);
        $collectionPct = number_format((float) ($row['collection_percentage'] ?? 0), 1);
        $fieldPct = number_format((float) ($row['field_activity_percentage'] ?? 0), 1);
        $overallPct = number_format((float) ($row['overall_percentage'] ?? 0), 1);

        return implode("\n", [
            'ParamGold Monthly Performance',
            '',
            'Employee: '.(string) ($row['employee_name'] ?? ''),
            'Month: '.$monthLabel,
            '',
            'Sales',
            'Target: '.IndianCurrency::format($row['sales_target'] ?? 0),
            'Achieved: '.IndianCurrency::format($row['sales_achieved'] ?? 0),
            'Achievement: '.$salesPct.'%',
            '',
            'Collection',
            'Target: '.IndianCurrency::format($row['collection_target'] ?? 0),
            'Achieved: '.IndianCurrency::format($row['collection_achieved'] ?? 0),
            'Achievement: '.$collectionPct.'%',
            '',
            'Field Activity',
            'Target: '.(int) ($row['field_activity_target'] ?? 0),
            'Achieved: '.(int) ($row['field_activity_achieved'] ?? 0),
            'Achievement: '.$fieldPct.'%',
            '',
            'Overall Performance: '.$overallPct.'%',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareUrl(array $row, string $monthLabel): string
    {
        return 'https://wa.me/?text='.rawurlencode(self::whatsappShareMessage($row, $monthLabel));
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
