<?php

namespace App\Filament\Resources\TransportFreightLedgers\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\TransportFreightLedgers\TransportFreightLedgerResource;
use App\Models\TransportFreightLedger;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTransportFreightLedger extends ViewRecord
{
    protected static string $resource = TransportFreightLedgerResource::class;

    protected function getHeaderActions(): array
    {
        /** @var TransportFreightLedger $record */
        $record = $this->getRecord();

        return [
            Action::make('openPurchase')
                ->label('Open Purchase')
                ->url(fn (): string => PurchaseResource::getUrl('view', ['record' => $record->purchase_id]))
                ->visible(fn (): bool => $record->purchase_id !== null),
        ];
    }
}
