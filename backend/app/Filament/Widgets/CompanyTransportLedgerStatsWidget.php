<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Services\Orders\CompanyTransportLedgerService;
use App\Support\IndianCurrency;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class CompanyTransportLedgerStatsWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.company-transport-ledger-stats-widget';

    public string $activeView = 'all';

    public static function canView(): bool
    {
        return CompanyTransportLedgerResource::canAccess();
    }

    public function selectView(string $view): void
    {
        $this->activeView = $this->normalizeView($view);
        $this->dispatch('company-transport-ledger-view', view: $this->activeView);
    }

    #[On('company-transport-ledger-view')]
    public function syncView(string $view): void
    {
        $this->activeView = $this->normalizeView($view);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $summary = app(CompanyTransportLedgerService::class)->liveSummary();

        return [
            'cards' => [
                [
                    'label' => 'Total Transport Collected',
                    'value' => IndianCurrency::formatExact($summary['total_collected']),
                    'meta' => 'Credits from Company Transport and Transport Charges Extra',
                    'tone' => 'success',
                    'active' => $this->activeView === 'collected',
                    'wireClick' => "selectView('collected')",
                ],
                [
                    'label' => 'Total Transport Expense',
                    'value' => IndianCurrency::formatExact($summary['total_expense']),
                    'meta' => 'Manual debit expenses',
                    'tone' => 'danger',
                    'active' => $this->activeView === 'expense',
                    'wireClick' => "selectView('expense')",
                ],
                [
                    'label' => 'Current Balance',
                    'value' => IndianCurrency::formatExact($summary['current_balance']),
                    'meta' => 'Total Credits − Total Debits',
                    'tone' => $summary['current_balance'] >= 0 ? 'primary' : 'warning',
                    'active' => $this->activeView === 'all',
                    'wireClick' => "selectView('all')",
                ],
            ],
        ];
    }

    private function normalizeView(string $view): string
    {
        return in_array($view, ['all', 'collected', 'expense'], true) ? $view : 'all';
    }
}
