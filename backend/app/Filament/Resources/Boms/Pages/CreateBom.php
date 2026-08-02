<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Enums\BomStatus;
use App\Filament\Resources\Boms\BomResource;
use App\Services\Inventory\BOMCalculationService;
use Filament\Resources\Pages\CreateRecord;

class CreateBom extends CreateRecord
{
    protected static string $resource = BomResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? BomResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Bill of Material created successfully.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        unset($data['bom_version']);
        $data['wastage_percentage'] = 0;

        return $data;
    }

    protected function beforeCreate(): void
    {
        $state = $this->data;

        app(BOMCalculationService::class)->assertBomFormulaForSave(
            $state,
            $state['items'] ?? [],
            $state['status'] ?? BomStatus::Inactive->value,
        );
    }

    protected function afterCreate(): void
    {
        $bom = $this->getRecord()->load(['items', 'product']);
        $bom->items()->update(['is_optional' => false, 'wastage_percentage' => 0]);
        app(BOMCalculationService::class)->syncCalculatedQuantities($bom->fresh(['items']));
        app(BOMCalculationService::class)->ensureSingleActiveBom($bom->fresh(['product', 'items']));
    }
}
