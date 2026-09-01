<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithManagerDashboardFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;

class ManagerTeamPerformanceWidget extends Widget
{
    use InteractsWithManagerDashboardFilters;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.manager-team-performance-widget';

    #[Url(as: 'team_period')]
    public string $teamPeriod = 'monthly';

    #[Url(as: 'team_from_date')]
    public ?string $teamFromDate = null;

    #[Url(as: 'team_to_date')]
    public ?string $teamToDate = null;

    public ?string $teamCustomFromDate = null;

    public ?string $teamCustomToDate = null;

    public static function canView(): bool
    {
        return auth()->user()?->usesManagerDashboard() ?? false;
    }

    public function mount(): void
    {
        $this->teamPeriod = $this->managerPeriodQueryValue($this->teamPeriod);

        if ($this->normalizeManagerPeriod($this->teamPeriod) === 'custom') {
            $this->teamCustomFromDate = $this->teamFromDate;
            $this->teamCustomToDate = $this->teamToDate;
        }
    }

    public function setTeamPeriod(string $period): void
    {
        $normalized = $this->normalizeManagerPeriod($period);

        if ($normalized === 'custom') {
            $this->teamPeriod = 'custom';
            $this->teamCustomFromDate ??= now('Asia/Kolkata')->startOfMonth()->toDateString();
            $this->teamCustomToDate ??= now('Asia/Kolkata')->toDateString();

            return;
        }

        $this->teamPeriod = $this->managerPeriodQueryValue($normalized);
        $this->teamFromDate = null;
        $this->teamToDate = null;
        $this->teamCustomFromDate = null;
        $this->teamCustomToDate = null;
        $this->resetErrorBag();
    }

    public function applyTeamCustomPeriod(): void
    {
        $this->validate([
            'teamCustomFromDate' => ['required', 'date'],
            'teamCustomToDate' => ['required', 'date', 'after_or_equal:teamCustomFromDate'],
        ], [
            'teamCustomFromDate.required' => 'From Date is required.',
            'teamCustomToDate.required' => 'To Date is required.',
            'teamCustomToDate.after_or_equal' => 'To Date cannot be earlier than From Date.',
        ]);

        $this->teamPeriod = 'custom';
        $this->teamFromDate = $this->teamCustomFromDate;
        $this->teamToDate = $this->teamCustomToDate;
    }

    public function resetTeamCustomPeriod(): void
    {
        $this->teamPeriod = 'monthly';
        $this->teamFromDate = null;
        $this->teamToDate = null;
        $this->teamCustomFromDate = null;
        $this->teamCustomToDate = null;
        $this->resetErrorBag();
    }

    public function isActiveTeamPeriod(string $period): bool
    {
        return $this->normalizeManagerPeriod($this->teamPeriod) === $this->normalizeManagerPeriod($period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $data = $this->fetchManagerTeamSummaryData(
            $this->teamPeriod,
            $this->teamFromDate,
            $this->teamToDate,
        );

        $summary = $data['summary'];

        return [
            'summary' => $summary,
            'heading' => $this->managerPeriodHeading($this->teamPeriod, 'Team Performance'),
            'periodLabel' => $this->managerPeriodRangeText($data['range']),
            'periodFilters' => $this->managerPeriodFilterOptions(),
            'showCustomPeriod' => $this->normalizeManagerPeriod($this->teamPeriod) === 'custom',
            'formatMoney' => fn (float $amount): string => Number::currency($amount, 'INR', 'en_IN'),
            'formatPercentage' => fn (float $percentage): string => number_format($percentage, 2).'%',
            'salesBarWidth' => min((float) $summary['sales_percentage'], 100),
            'collectionBarWidth' => min((float) $summary['collection_percentage'], 100),
        ];
    }
}
