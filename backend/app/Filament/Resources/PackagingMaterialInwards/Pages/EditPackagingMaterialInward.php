<?php

namespace App\Filament\Resources\PackagingMaterialInwards\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\PackagingMaterialInwards\PackagingMaterialInwardResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit is disabled — create always posts immediately. Kept only for route safety.
 */
class EditPackagingMaterialInward extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = PackagingMaterialInwardResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->redirect(PackagingMaterialInwardResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
