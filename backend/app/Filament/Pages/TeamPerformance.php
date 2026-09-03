<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Concerns\InteractsWithManagerDashboardFilters;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\FieldActivities\FieldActivityResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Support\IndianCurrency;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

class TeamPerformance extends Page
{
    use InteractsWithManagerDashboardFilters;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Team Performance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Team Performance';

    protected static ?string $slug = 'team-performance';

    protected string $view = 'filament.pages.team-performance';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'period', history: true, keep: true)]
    public string $period = 'weekly';

    #[Url(as: 'from_date', history: true, keep: true)]
    public ?string $fromDate = null;

    #[Url(as: 'to_date', history: true, keep: true)]
    public ?string $toDate = null;

    public ?string $customFromDate = null;

    public ?string $customToDate = null;

    public ?int $detailEmployeeId = null;

    public ?string $detailType = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->period = $this->managerPeriodQueryValue($this->period);

        if ($this->normalizeManagerPeriod($this->period) === 'custom') {
            $this->customFromDate = $this->fromDate;
            $this->customToDate = $this->toDate;
        }
    }

    public function setPeriod(string $period): void
    {
        $normalized = $this->normalizeManagerPeriod($period);

        if ($normalized === 'custom') {
            $this->period = 'custom';
            $this->customFromDate ??= Carbon::now('Asia/Kolkata')->startOfWeek(Carbon::MONDAY)->toDateString();
            $this->customToDate ??= Carbon::now('Asia/Kolkata')->toDateString();
            $this->fromDate = $this->customFromDate;
            $this->toDate = $this->customToDate;

            return;
        }

        $this->period = $this->managerPeriodQueryValue($normalized);
        $this->fromDate = null;
        $this->toDate = null;
        $this->customFromDate = null;
        $this->customToDate = null;
        $this->resetErrorBag();
    }

    public function applyCustomPeriod(): void
    {
        $this->validate([
            'customFromDate' => ['required', 'date'],
            'customToDate' => ['required', 'date', 'after_or_equal:customFromDate'],
        ], [
            'customFromDate.required' => 'From Date is required.',
            'customToDate.required' => 'To Date is required.',
            'customToDate.after_or_equal' => 'To Date cannot be earlier than From Date.',
        ]);

        $this->period = 'custom';
        $this->fromDate = $this->customFromDate;
        $this->toDate = $this->customToDate;
    }

    public function resetCustomPeriod(): void
    {
        $this->period = 'weekly';
        $this->fromDate = null;
        $this->toDate = null;
        $this->customFromDate = null;
        $this->customToDate = null;
        $this->resetErrorBag();
    }

    public function isActivePeriod(string $period): bool
    {
        return $this->normalizeManagerPeriod($this->period) === $this->normalizeManagerPeriod($period);
    }

    public function openDetail(int $employeeId, string $type): void
    {
        if (! in_array($type, ['sales', 'collection', 'field_activity'], true)) {
            return;
        }

        if ($this->detailEmployeeId === $employeeId && $this->detailType === $type) {
            $this->closeDetail();

            return;
        }

        $this->detailEmployeeId = $employeeId;
        $this->detailType = $type;
    }

    public function closeDetail(): void
    {
        $this->detailEmployeeId = null;
        $this->detailType = null;
    }

    public function isMetricActive(int $employeeId, string $type): bool
    {
        return $this->detailEmployeeId === $employeeId && $this->detailType === $type;
    }

    /**
     * @return array<string, string>
     */
    public function periodFilters(): array
    {
        return [
            'last_week' => 'Last Week',
            'weekly' => 'This Week',
            'last_month' => 'Last Month',
            'monthly' => 'This Month',
            'custom' => 'Custom Date Range',
        ];
    }

    public function showCustomPeriod(): bool
    {
        return $this->normalizeManagerPeriod($this->period) === 'custom';
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public function range(): array
    {
        $normalized = $this->normalizeManagerPeriod($this->period);
        $metrics = app(DashboardMetricsService::class);

        if ($normalized === 'custom') {
            if (blank($this->fromDate) || blank($this->toDate)) {
                return $metrics->resolveDateRange('week');
            }

            return $metrics->resolveDateRange('custom', $this->fromDate, $this->toDate);
        }

        return $metrics->resolveDateRange($normalized);
    }

    public function periodLabel(): string
    {
        $range = $this->range();

        return $range['label'].' · '.$this->managerPeriodRangeText($range);
    }

    public function periodShareLabel(): string
    {
        $range = $this->range();

        return $range['label'].' ('.$this->managerPeriodRangeText($range).')';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employees(): array
    {
        $range = $this->range();
        $period = $this->normalizeManagerPeriod($this->period);

        $employees = collect(app(DashboardMetricsService::class)->employeePerformance(
            $range['start'],
            $range['end'],
            role: UserRole::Employee->value,
            period: $period,
        ))->all();

        usort($employees, function (array $left, array $right): int {
            $leftPct = $left['overall_percentage'];
            $rightPct = $right['overall_percentage'];

            if ($leftPct === null && $rightPct === null) {
                return strcmp((string) $left['employee_name'], (string) $right['employee_name']);
            }
            if ($leftPct === null) {
                return 1;
            }
            if ($rightPct === null) {
                return -1;
            }

            $compare = $rightPct <=> $leftPct;

            return $compare !== 0
                ? $compare
                : strcmp((string) $left['employee_name'], (string) $right['employee_name']);
        });

        return array_values($employees);
    }

    public function selectedEmployeeName(): ?string
    {
        if ($this->detailEmployeeId === null) {
            return null;
        }

        foreach ($this->employees() as $employee) {
            if ((int) $employee['employee_id'] === $this->detailEmployeeId) {
                return (string) $employee['employee_name'];
            }
        }

        return null;
    }

    public function detailHeading(): string
    {
        $employeeName = $this->selectedEmployeeName() ?? 'Employee';
        $metric = match ($this->detailType) {
            'sales' => 'Sales orders',
            'collection' => 'Collection entries',
            'field_activity' => 'Field activity records',
            default => 'Records',
        };

        return $metric.' · '.$employeeName;
    }

    public function detailTotalLabel(): string
    {
        return match ($this->detailType) {
            'sales' => 'Total Sales Amount',
            'collection' => 'Total Collection Amount',
            'field_activity' => 'Total Field Activities',
            default => 'Total',
        };
    }

    public function detailTotalDisplay(): string
    {
        $rows = $this->detailRows();

        return match ($this->detailType) {
            'sales' => $this->formatExactMoney(round(array_sum(array_column($rows, 'grand_total')), 2)),
            'collection' => $this->formatExactMoney(round(array_sum(array_column($rows, 'amount')), 2)),
            'field_activity' => (string) count($rows),
            default => '0',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function detailRows(): array
    {
        if ($this->detailEmployeeId === null || $this->detailType === null) {
            return [];
        }

        $range = $this->range();
        $metrics = app(DashboardMetricsService::class);

        return match ($this->detailType) {
            'sales' => collect($metrics->employeeOrdersForPeriod(
                $this->detailEmployeeId,
                $range['start'],
                $range['end'],
            ))->map(fn (array $row): array => [
                ...$row,
                'url' => OrderResource::getUrl('view', ['record' => $row['id']]),
            ])->all(),
            'collection' => collect($metrics->employeeCollectionsForPeriod(
                $this->detailEmployeeId,
                $range['start'],
                $range['end'],
            ))->map(fn (array $row): array => [
                ...$row,
                'url' => CollectionResource::getUrl('view', ['record' => $row['id']]),
            ])->all(),
            'field_activity' => collect($metrics->employeeFieldActivitiesForPeriod(
                $this->detailEmployeeId,
                $range['start'],
                $range['end'],
            ))->map(fn (array $row): array => [
                ...$row,
                'url' => FieldActivityResource::getUrl('view', ['record' => $row['id']]),
            ])->all(),
            default => [],
        };
    }

    public function formatMoney(float $amount): string
    {
        return DirectorDashboardDataService::formatCompact($amount);
    }

    public function formatExactMoney(float $amount): string
    {
        return IndianCurrency::format($amount);
    }

    public function formatPct(?float $percentage, float|int|null $target = null): string
    {
        if ($target !== null && (float) $target <= 0) {
            return 'N/A';
        }

        if ($percentage === null) {
            return 'N/A';
        }

        return number_format($percentage, 0).'%';
    }

    public function barWidth(?float $percentage, float|int|null $target = null): float
    {
        if ($target !== null && (float) $target <= 0) {
            return 0;
        }

        if ($percentage === null) {
            return 0;
        }

        return min(max($percentage, 0), 100);
    }

    public function metricHasTarget(array $employee, string $metric): bool
    {
        $key = match ($metric) {
            'sales' => 'sales_target',
            'collection' => 'collection_target',
            'field_activity' => 'field_activity_target',
            default => 'sales_target',
        };

        return (float) ($employee[$key] ?? 0) > 0;
    }

    public function overallHasTarget(array $employee): bool
    {
        return $this->metricHasTarget($employee, 'sales')
            || $this->metricHasTarget($employee, 'collection')
            || $this->metricHasTarget($employee, 'field_activity');
    }

    public function formatDate(?string $date): string
    {
        if (blank($date)) {
            return '—';
        }

        return Carbon::parse($date, 'Asia/Kolkata')->format('d M Y');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function whatsappUrl(array $row): string
    {
        return self::whatsappShareUrl($row, $this->periodShareLabel());
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareMessage(array $row, string $periodLabel): string
    {
        $salesPct = self::formatSharePct($row['sales_percentage'] ?? null, $row['sales_target'] ?? 0);
        $collectionPct = self::formatSharePct($row['collection_percentage'] ?? null, $row['collection_target'] ?? 0);
        $fieldPct = self::formatSharePct($row['field_activity_percentage'] ?? null, $row['field_activity_target'] ?? 0);
        $overallPct = self::formatSharePct($row['overall_percentage'] ?? null, (
            ((float) ($row['sales_target'] ?? 0) > 0)
            || ((float) ($row['collection_target'] ?? 0) > 0)
            || ((float) ($row['field_activity_target'] ?? 0) > 0)
        ) ? 1 : 0);

        return implode("\n", [
            'ParamGold Team Performance',
            '',
            'Employee: '.(string) ($row['employee_name'] ?? ''),
            'Period: '.$periodLabel,
            '',
            'Sales',
            'Target: '.IndianCurrency::format($row['sales_target'] ?? 0),
            'Achieved: '.IndianCurrency::format($row['sales_achieved'] ?? 0),
            'Achievement: '.$salesPct,
            '',
            'Collection',
            'Target: '.IndianCurrency::format($row['collection_target'] ?? 0),
            'Achieved: '.IndianCurrency::format($row['collection_achieved'] ?? 0),
            'Achievement: '.$collectionPct,
            '',
            'Field Activity',
            'Target: '.(int) ($row['field_activity_target'] ?? 0),
            'Achieved: '.(int) ($row['field_activity_achieved'] ?? 0),
            'Achievement: '.$fieldPct,
            '',
            'Overall Performance: '.$overallPct,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function whatsappShareUrl(array $row, string $periodLabel): string
    {
        return 'https://wa.me/?text='.rawurlencode(self::whatsappShareMessage($row, $periodLabel));
    }

    private static function formatSharePct(mixed $percentage, mixed $target): string
    {
        if ((float) $target <= 0 || $percentage === null) {
            return 'N/A';
        }

        return number_format((float) $percentage, 1).'%';
    }
}
