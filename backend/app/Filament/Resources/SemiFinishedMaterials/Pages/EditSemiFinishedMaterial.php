<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Resources\SemiFinishedMaterials\Schemas\SemiFinishedMaterialForm;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use App\Models\SemiFinishedMaterial;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditSemiFinishedMaterial extends EditRecord
{
    protected static string $resource = SemiFinishedMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        return SemiFinishedMaterialForm::configureEdit($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            SafeDeleteActions::deactivateAction()
                ->authorize(fn (): bool => SemiFinishedMaterialResource::canEdit($this->getRecord())),
            SafeDeleteActions::deleteAction()
                ->authorize(fn (): bool => SemiFinishedMaterialResource::canDelete($this->getRecord())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var SemiFinishedMaterial $record */
        $record = $this->getRecord();

        $openingLedger = $record->stockLedgers()
            ->where('transaction_type', StockTransactionType::OpeningStock)
            ->orderBy('id')
            ->first();

        $qty = (float) $record->opening_stock;
        $rate = $openingLedger !== null
            ? (float) $openingLedger->rate
            : (float) $record->average_production_cost;
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
        // Opening stock is create-only. Never update stock columns or re-post ledger on Edit.
        unset(
            $data['opening_stock_quantity'],
            $data['opening_stock_value'],
            $data['opening_date'],
            $data['opening_effective_rate'],
            $data['opening_stock'],
            $data['current_stock'],
            $data['current_stock_value'],
            $data['average_production_cost'],
            $data['material_code'],
        );

        return $data;
    }
}
