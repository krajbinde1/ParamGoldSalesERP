<?php

namespace App\Filament\Resources\PackagingMaterials\Pages;

use App\Filament\Resources\PackagingMaterials\PackagingMaterialResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPackagingMaterial extends ViewRecord
{
    protected static string $resource = PackagingMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->authorize(fn (): bool => PackagingMaterialResource::canEdit($this->getRecord())),
        ];
    }
}
