<?php

namespace App\Filament\Resources\RawMaterials\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\RawMaterials\RawMaterialResource;
use App\Filament\Resources\RawMaterials\Schemas\RawMaterialForm;
use App\Models\RawMaterial;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditRawMaterial extends EditRecord
{
    protected static string $resource = RawMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        return RawMaterialForm::configureEdit($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction(),
            SafeDeleteActions::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RawMaterial $record */
        $record = $this->getRecord();

        $openingLedger = $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();

        $qty = (float) $record->opening_stock;
        $rate = $openingLedger !== null
            ? (float) $openingLedger->rate
            : (float) $record->purchase_rate;
        $value = $openingLedger !== null
            ? (float) $openingLedger->transaction_value
            : round($qty * $rate, 2);

        $data['opening_stock_quantity'] = $qty;
        $data['opening_stock_value'] = $value;
        $data['opening_date'] = $openingLedger?->transaction_date?->toDateString()
            ?? ($qty > 0 && $record->created_at !== null
                ? $record->created_at->timezone('Asia/Kolkata')->toDateString()
                : null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Preserve existing category — the field is removed from the UI.
        unset($data['category']);

        // Opening stock is create-only. Never update stock columns or re-post ledger on Edit.
        unset(
            $data['opening_stock_quantity'],
            $data['opening_stock_value'],
            $data['opening_date'],
            $data['opening_effective_rate'],
            $data['opening_stock'],
            $data['current_stock'],
            $data['current_stock_value'],
            $data['purchase_rate'],
            $data['average_rate'],
        );

        return $data;
    }
}
