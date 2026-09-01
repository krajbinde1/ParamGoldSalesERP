<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithManagerDashboardFilters;
use App\Filament\Pages\ManagerEmployeePerformanceDetail;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;

class ManagerEmployeePerformanceWidget extends Widget
{
    use InteractsWithManagerDashboardFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.manager-employee-performance-widget';

    #[Url(as: 'period')]
    public string $period = 'monthly';

    #[Url(as: 'employee_id')]
    public string $employeeId = 'all';

    #[Url(as: 'from_date')]
    public ?string $fromDate = null;

    #[Url(as: 'to_date')]
    public ?string $toDate = null;

    public ?string $customFromDate = null;

    public ?string $customToDate = null;

    public string $employeeSearch = '';

    public static function canView(): bool
    {
        return auth()->user()?->usesManagerDashboard() ?? false;
    }

    public function mount(): void
    {
        $this->period = $this->managerPeriodQueryValue($this->period);

        if ($this->normalizeManagerPeriod($this->period) === 'custom') {
            $this->customFromDate = $this->fromDate;
            $this->customToDate = $this->toDate;
        }
    }

    public function setManagerPeriod(string $period): void
    {
        $normalized = $this->normalizeManagerPeriod($period);

        if ($normalized === 'custom') {
            $this->period = 'custom';
            $this->customFromDate ??= now('Asia/Kolkata')->startOfMonth()->toDateString();
            $this->customToDate ??= now('Asia/Kolkata')->toDateString();

            return;
        }

        $this->period = $this->managerPeriodQueryValue($normalized);
        $this->fromDate = null;
        $this->toDate = null;
        $this->customFromDate = null;
        $this->customToDate = null;
        $this->resetErrorBag();
    }

    public function applyManagerCustomPeriod(): void
    {
        $this->validate([
            'customFromDate' => ['required', 'date'],
            'customToDate' => ['required', 'date', 'after_or_equal:customFromDate'],
        ], [
            'customFromDate.required' => 'From Date is required.',
            'customToDate.required' => 'To Date is required.',
            'customToDate.after_or_equal' => 'To Date cannot be before From Date.',
        ]);

        $this->period = 'custom';
        $this->fromDate = $this->customFromDate;
        $this->toDate = $this->customToDate;
    }

    public function clearManagerCustomPeriod(): void
    {
        $this->period = 'monthly';
        $this->fromDate = null;
        $this->toDate = null;
        $this->customFromDate = null;
        $this->customToDate = null;
        $this->resetErrorBag();
    }

    public function updatedEmployeeId(): void
    {
        if ($this->employeeId !== 'all' && $this->resolveManagerEmployeeId($this->employeeId) === null) {
            $this->employeeId = 'all';
        }
    }

    public function updatedEmployeeSearch(): void
    {
        // Re-render employee dropdown options only.
    }

    public function isActiveManagerPeriod(string $period): bool
    {
        return $this->normalizeManagerPeriod($this->period) === $this->normalizeManagerPeriod($period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = $this->fetchManagerPerformanceData(
            $this->period,
            $this->employeeId,
            $this->fromDate,
            $this->toDate,
        );

        return [
            'employees' => $data['employees'],
            'heading' => $this->managerPeriodHeading($this->period, 'Employee Performance'),
            'periodLabel' => $this->managerPeriodRangeText($data['range']),
            'periodFilters' => $this->managerPeriodFilterOptions(),
            'employeeOptions' => $this->managerEmployeeOptions($this->employeeSearch),
            'showCustomPeriod' => $this->normalizeManagerPeriod($this->period) === 'custom',
            'detailUrl' => fn (int $employeeId): string => ManagerEmployeePerformanceDetail::getUrl([
                'employee' => $employeeId,
            ]),
            'formatMoney' => fn (float $amount): string => Number::currency($amount, 'INR', 'en_IN'),
            'formatPercentage' => fn (float $percentage): string => number_format($percentage, 2).'%',
        ];
    }
}
