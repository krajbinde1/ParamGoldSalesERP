<?php

namespace App\Filament\Resources\PackagingMaterials\Pages;

use App\Enums\StockTransactionType;
use App\Filament\Actions\SafeDeleteActions;
use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Concerns\SyncsMaterialOpeningStockOnEdit;
use App\Filament\Resources\PackagingMaterials\PackagingMaterialResource;
use App\Filament\Resources\PackagingMaterials\Schemas\PackagingMaterialForm;
use App\Models\PackagingMaterial;
use App\Models\User;
use App\Services\Inventory\MaterialOpeningStockSyncService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditPackagingMaterial extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;
    use SyncsMaterialOpeningStockOnEdit;

    protected static string $resource = PackagingMaterialResource::class;

    public function form(Schema $schema): Schema
    {
        return PackagingMaterialForm::configureEdit($schema);
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
        /** @var PackagingMaterial $record */
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
            : PackagingMaterialForm::openingStockValue($qty, $rate);

        $data['opening_stock_quantity'] = $qty;
        $data['opening_stock_value'] = $value;
        $data['opening_effective_rate'] = $rate > 0 ? $rate : 0;
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

        return $this->extractOpeningStockAndUnset($data, [
            'opening_stock',
            'current_stock',
            'current_stock_value',
            'purchase_rate',
            'average_rate',
        ]);
    }

    protected function beforeSave(): void
    {
        $qty = round((float) ($this->data['opening_stock_quantity'] ?? 0), 3);
        $rate = round((float) ($this->data['opening_effective_rate'] ?? 0), 4);
        $this->data['opening_stock_value'] = PackagingMaterialForm::openingStockValue($qty, $rate);

        $this->applyPendingOpeningStock(function (array $opening, User $user): void {
            app(MaterialOpeningStockSyncService::class)->syncPackagingMaterial(
                $this->getRecord(),
                $opening,
                $user,
            );
        });
    }
}
