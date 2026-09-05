<?php

namespace App\Filament\Resources\Crops\Pages;

use App\Filament\Concerns\RedirectsToPreviousPageAfterSave;
use App\Filament\Resources\Crops\CropResource;
use Filament\Resources\Pages\EditRecord;

class EditCrop extends EditRecord
{
    use RedirectsToPreviousPageAfterSave;

    protected static string $resource = CropResource::class;
}
