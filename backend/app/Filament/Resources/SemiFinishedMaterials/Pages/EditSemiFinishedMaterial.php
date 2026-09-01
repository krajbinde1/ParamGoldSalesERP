<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\SyncsMaterialOpeningStockOnEdit;
use App\Filament\Resources\SemiFinishedMaterials\Schemas\SemiFinishedMaterialForm;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use App\Services\Inventory\MaterialOpeningStockSyncService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditSemiFinishedMaterial extends EditRecord
{
    use SyncsMaterialOpeningStockOnEdit;

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
        unset($data['material_code']);

        return $this->extractOpeningStockAndUnset($data, [
            'opening_stock',
            'current_stock',
            'current_stock_value',
            'average_production_cost',
        ]);
    }

    protected function beforeSave(): void
    {
        $this->applyPendingOpeningStock(function (array $opening, User $user): void {
            app(MaterialOpeningStockSyncService::class)->syncSemiFinishedMaterial(
                $this->getRecord(),
                $opening,
                $user,
            );
        });
    }
}
