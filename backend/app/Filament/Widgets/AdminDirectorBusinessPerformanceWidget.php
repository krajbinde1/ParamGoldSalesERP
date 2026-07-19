<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithManagerDashboardFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;

class AdminDirectorBusinessPerformanceWidget extends Widget
{
    use InteractsWithManagerDashboardFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-business-performance-widget';

    #[Url(as: 'biz_period')]
    public string $bizPeriod = 'monthly';

    #[Url(as: 'biz_from_date')]
    public ?string $bizFromDate = null;

    #[Url(as: 'biz_to_date')]
    public ?string $bizToDate = null;

    public ?string $bizCustomFromDate = null;

    public ?string $bizCustomToDate = null;

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    public function mount(): void
    {
        $this->bizPeriod = $this->managerPeriodQueryValue($this->bizPeriod);

        if ($this->normalizeManagerPeriod($this->bizPeriod) === 'custom') {
            $this->bizCustomFromDate = $this->bizFromDate;
            $this->bizCustomToDate = $this->bizToDate;
        }
    }

    public function setBizPeriod(string $period): void
    {
        $normalized = $this->normalizeManagerPeriod($period);

        if ($normalized === 'custom') {
            $this->bizPeriod = 'custom';
            $this->bizCustomFromDate ??= now('Asia/Kolkata')->startOfMonth()->toDateString();
            $this->bizCustomToDate ??= now('Asia/Kolkata')->toDateString();

            return;
        }

        $this->bizPeriod = $this->managerPeriodQueryValue($normalized);
        $this->bizFromDate = null;
        $this->bizToDate = null;
        $this->bizCustomFromDate = null;
        $this->bizCustomToDate = null;
        $this->resetErrorBag();
    }

    public function applyBizCustomPeriod(): void
    {
        $this->validate([
            'bizCustomFromDate' => ['required', 'date'],
            'bizCustomToDate' => ['required', 'date', 'after_or_equal:bizCustomFromDate'],
        ], [
            'bizCustomFromDate.required' => 'From Date is required.',
            'bizCustomToDate.required' => 'To Date is required.',
            'bizCustomToDate.after_or_equal' => 'To Date cannot be earlier than From Date.',
        ]);

        $this->bizPeriod = 'custom';
        $this->bizFromDate = $this->bizCustomFromDate;
        $this->bizToDate = $this->bizCustomToDate;
    }

    public function resetBizCustomPeriod(): void
    {
        $this->bizPeriod = 'monthly';
        $this->bizFromDate = null;
        $this->bizToDate = null;
        $this->bizCustomFromDate = null;
        $this->bizCustomToDate = null;
        $this->resetErrorBag();
    }

    public function isActiveBizPeriod(string $period): bool
    {
        return $this->normalizeManagerPeriod($this->bizPeriod) === $this->normalizeManagerPeriod($period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = $this->fetchManagerTeamSummaryData(
            $this->bizPeriod,
            $this->bizFromDate,
            $this->bizToDate,
        );

        $summary = $data['summary'];

        return [
            'summary' => $summary,
            'periodLabel' => $data['range']['label'],
            'showCustomPeriod' => $this->normalizeManagerPeriod($this->bizPeriod) === 'custom',
            'formatMoney' => fn (float $amount): string => Number::currency($amount, 'INR', 'en_IN'),
            'formatPercentage' => fn (float $percentage): string => number_format($percentage, 2).'%',
            'salesBarWidth' => min((float) $summary['sales_percentage'], 100),
            'collectionBarWidth' => min((float) $summary['collection_percentage'], 100),
        ];
    }
}
