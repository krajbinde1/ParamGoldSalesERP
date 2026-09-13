<?php

namespace App\Filament\Pages;

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

class SalesDetails extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Sales Details';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $title = 'Sales Details';

    protected static ?string $slug = 'sales-details';

    protected string $view = 'filament.pages.sales-details';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'period', history: true, keep: true)]
    public string $period = 'today';

    #[Url(as: 'from_date', history: true, keep: true)]
    public ?string $fromDate = null;

    #[Url(as: 'to_date', history: true, keep: true)]
    public ?string $toDate = null;

    public ?string $customFromDate = null;

    public ?string $customToDate = null;

    public ?int $expandedDealerId = null;

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

        if (! in_array($this->period, DashboardMetricsService::PERIOD_KEYS, true)) {
            $this->period = 'today';
        }

        if ($this->period === 'custom') {
            $this->customFromDate = $this->fromDate;
            $this->customToDate = $this->toDate;
        }
    }

    public function setPeriod(string $period): void
    {
        if (! in_array($period, DashboardMetricsService::PERIOD_KEYS, true)) {
            return;
        }

        $this->expandedDealerId = null;

        if ($period === 'custom') {
            $this->period = 'custom';
            $this->customFromDate ??= Carbon::now('Asia/Kolkata')->toDateString();
            $this->customToDate ??= Carbon::now('Asia/Kolkata')->toDateString();
            $this->fromDate = $this->customFromDate;
            $this->toDate = $this->customToDate;
            $this->resetErrorBag();

            return;
        }

        $this->period = $period;
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
        $this->expandedDealerId = null;
    }

    public function resetCustomPeriod(): void
    {
        $this->period = 'today';
        $this->fromDate = null;
        $this->toDate = null;
        $this->customFromDate = null;
        $this->customToDate = null;
        $this->expandedDealerId = null;
        $this->resetErrorBag();
    }

    public function isActivePeriod(string $period): bool
    {
        return $this->period === $period;
    }

    public function toggleParty(?int $dealerId): void
    {
        $key = $dealerId ?? 0;

        $this->expandedDealerId = $this->expandedDealerId === $key ? null : $key;
    }

    public function isPartyExpanded(?int $dealerId): bool
    {
        return $this->expandedDealerId === ($dealerId ?? 0);
    }

    /**
     * @return array<string, string>
     */
    public function periodFilters(): array
    {
        return [
            'today' => 'Today',
            'week' => 'This Week',
            'last_week' => 'Last Week',
            'month' => 'This Month',
            'last_month' => 'Last Month',
            'year' => 'This Year',
            'custom' => 'Custom Date Range',
        ];
    }

    public function showCustomPeriod(): bool
    {
        return $this->period === 'custom';
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string}
     */
    public function range(): array
    {
        $metrics = app(DashboardMetricsService::class);

        if ($this->period === 'custom' && (blank($this->fromDate) || blank($this->toDate))) {
            return $metrics->resolveDateRange('today');
        }

        return $metrics->resolveDateRange(
            $this->period,
            $this->fromDate,
            $this->toDate,
        );
    }

    public function periodLabel(): string
    {
        $range = $this->range();
        $start = $range['start']->timezone('Asia/Kolkata')->format('d M Y');
        $end = $range['end']->timezone('Asia/Kolkata')->format('d M Y');

        return $start === $end ? $range['label'].' · '.$start : $range['label'].' · '.$start.' – '.$end;
    }

    /**
     * @return array{
     *     total_sales: float,
     *     total_invoices: int,
     *     total_parties: int,
     *     parties: list<array<string, mixed>>
     * }
     */
    public function details(): array
    {
        $range = $this->range();

        return app(DirectorDashboardDataService::class)->dashboardSalesPartyDetails(
            $range['start'],
            $range['end'],
        );
    }

    public function orderUrl(int $orderId): string
    {
        return OrderResource::getUrl('view', ['record' => $orderId]);
    }

    public function formatMoney(float $amount): string
    {
        return IndianCurrency::format($amount);
    }

    public function formatDate(?string $date): string
    {
        if (! filled($date) || $date === '—') {
            return '—';
        }

        if (str_contains($date, ' – ')) {
            [$from, $to] = explode(' – ', $date, 2);

            return $this->formatDate($from).' – '.$this->formatDate($to);
        }

        return Carbon::parse($date, 'Asia/Kolkata')->format('d M Y');
    }

    public function partyKey(?int $dealerId): int
    {
        return $dealerId ?? 0;
    }
}
