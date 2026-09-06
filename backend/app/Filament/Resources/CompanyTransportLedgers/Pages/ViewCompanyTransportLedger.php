<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Pages;

use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompanyTransportLedger extends ViewRecord
{
    protected static string $resource = CompanyTransportLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => CompanyTransportLedgerResource::canEdit($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->getRecord()->loadMissing(['audits.actor:id,name']);

        return $data;
    }
}
