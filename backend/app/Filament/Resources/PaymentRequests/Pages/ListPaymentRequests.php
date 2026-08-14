<?php

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
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
}
