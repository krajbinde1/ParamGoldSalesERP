<?php

namespace App\Filament\Resources\StockLedgers\Pages;

use App\Filament\Resources\StockLedgers\StockLedgerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStockLedger extends ViewRecord
{
    protected static string $resource = StockLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
