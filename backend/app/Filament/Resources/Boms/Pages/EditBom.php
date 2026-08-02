<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Enums\BomStatus;
use App\Filament\Resources\Boms\BomResource;
use App\Services\Inventory\BOMCalculationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBom extends EditRecord
{
    protected static string $resource = BomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->authorize(fn (): bool => BomResource::canDelete($this->getRecord())),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? BomResource::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Bill of Material updated successfully.';
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();
        unset($data['bom_version']);
        $data['wastage_percentage'] = 0;

        return $data;
    }

    protected function beforeSave(): void
    {
        $state = $this->data;

        app(BOMCalculationService::class)->assertBomFormulaForSave(
            $state,
            $state['items'] ?? [],
            $state['status'] ?? BomStatus::Inactive->value,
        );
    }

    protected function afterSave(): void
    {
        $bom = $this->getRecord()->load(['items', 'product']);
        $bom->items()->update(['is_optional' => false, 'wastage_percentage' => 0]);
        app(BOMCalculationService::class)->syncCalculatedQuantities($bom->fresh(['items']));
        app(BOMCalculationService::class)->ensureSingleActiveBom($bom->fresh(['product', 'items']));
    }
}
