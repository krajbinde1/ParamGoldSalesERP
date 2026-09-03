<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Filament\Widgets\AdminDirectorPaymentOverviewWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkCreate')
                ->label('Create Bulk Payment Request')
                ->icon('heroicon-o-queue-list')
                ->url(PaymentRequestResource::getUrl('bulk-create')),
            CreateAction::make()
                ->label('New Payment Request'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AdminDirectorPaymentOverviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['pg-payment-requests-page'];
    }
}
