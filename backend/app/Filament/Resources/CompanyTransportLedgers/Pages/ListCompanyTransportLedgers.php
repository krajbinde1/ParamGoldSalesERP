<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Pages;

use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Filament\Widgets\CompanyTransportLedgerStatsWidget;
use App\Exports\Orders\CompanyTransportLedgerExport;
use App\Models\CompanyTransportLedgerEntry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListCompanyTransportLedgers extends ListRecords
{
    protected static string $resource = CompanyTransportLedgerResource::class;

    public string $ledgerView = 'all';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => auth()->user()?->can('export', CompanyTransportLedgerEntry::class) ?? false)
                ->action(fn (): BinaryFileResponse => $this->exportExcel()),
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->visible(fn (): bool => auth()->user()?->can('export', CompanyTransportLedgerEntry::class) ?? false)
                ->url(fn (): string => route('filament.admin.company-transport-ledger.pdf', $this->ledgerFilters()))
                ->openUrlInNewTab(),
            Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->visible(fn (): bool => auth()->user()?->can('export', CompanyTransportLedgerEntry::class) ?? false)
                ->url(fn (): string => route('filament.admin.company-transport-ledger.print', $this->ledgerFilters()))
                ->openUrlInNewTab(),
            CreateAction::make()
                ->label('Add Transport Expense'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CompanyTransportLedgerStatsWidget::make([
                'activeView' => $this->ledgerView,
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    #[On('company-transport-ledger-view')]
    public function applyLedgerView(string $view): void
    {
        $this->ledgerView = in_array($view, ['all', 'collected', 'expense'], true)
            ? $view
            : 'all';

        $this->resetTable();
    }

    public function exportExcel(): BinaryFileResponse
    {
        $filters = $this->ledgerFilters();
        $filename = 'Company_Transport_Ledger_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx';

        return Excel::download(new CompanyTransportLedgerExport($filters), $filename);
    }

    /**
     * @return array<string, mixed>
     */
    public function ledgerFilters(): array
    {
        $date = $this->getTableFilterState('transaction_date') ?? [];
        $vehicle = $this->getTableFilterState('vehicle_number') ?? [];
        $order = $this->getTableFilterState('order_no') ?? [];
        $transportType = $this->getTableFilterState('transport_charge_type') ?? [];
        $expense = $this->getTableFilterState('expense_type') ?? [];

        return array_filter([
            'from' => $date['from'] ?? null,
            'to' => $date['to'] ?? null,
            'vehicle_no' => $vehicle['value'] ?? null,
            'order_no' => $order['value'] ?? null,
            'transport_charge_type' => $transportType['value'] ?? null,
            'expense_type' => $expense['value'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
