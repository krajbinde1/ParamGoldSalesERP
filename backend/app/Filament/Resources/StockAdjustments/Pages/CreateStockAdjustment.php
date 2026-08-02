<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\Inventory\InventoryService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    /**
     * Stock adjustments must go through InventoryService so that the
     * related stock ledger entry and material/product balances stay in sync.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(InventoryService::class)->adjustStock($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        /** @var StockAdjustment $record */
        $record = $this->getRecord();

        return StockAdjustmentResource::getUrl('view', ['record' => $record]);
    }
}
