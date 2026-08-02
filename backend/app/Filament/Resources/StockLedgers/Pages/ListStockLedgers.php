<?php

namespace App\Filament\Resources\StockLedgers\Pages;

use App\Filament\Pages\InventoryReports;
use App\Filament\Resources\StockLedgers\StockLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListStockLedgers extends ListRecords
{
    protected static string $resource = StockLedgerResource::class;

    public function mount(): void
    {
        $this->redirect(InventoryReports::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
