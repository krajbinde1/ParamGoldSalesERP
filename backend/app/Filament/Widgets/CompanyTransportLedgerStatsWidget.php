<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Services\Orders\CompanyTransportLedgerService;
use App\Support\IndianCurrency;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CompanyTransportLedgerStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return CompanyTransportLedgerResource::canAccess();
    }

    protected function getStats(): array
    {
        $summary = app(CompanyTransportLedgerService::class)->liveSummary();

        return [
            Stat::make('Total Transport Collected', IndianCurrency::formatExact($summary['total_collected']))
                ->description('Credits from Company Transport and Transport Charges Extra')
                ->color('success'),
            Stat::make('Total Transport Expense', IndianCurrency::formatExact($summary['total_expense']))
                ->description('Manual debit expenses')
                ->color('danger'),
            Stat::make('Current Balance', IndianCurrency::formatExact($summary['current_balance']))
                ->description('Total Credits − Total Debits')
                ->color($summary['current_balance'] >= 0 ? 'primary' : 'warning'),
        ];
    }
}
