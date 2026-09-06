<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Pages;

use App\Filament\Resources\CompanyTransportLedgers\CompanyTransportLedgerResource;
use App\Services\Orders\CompanyTransportLedgerService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompanyTransportLedger extends CreateRecord
{
    protected static string $resource = CompanyTransportLedgerResource::class;

    public function getTitle(): string
    {
        return 'Add Transport Expense';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(CompanyTransportLedgerService::class)->recordExpense(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
