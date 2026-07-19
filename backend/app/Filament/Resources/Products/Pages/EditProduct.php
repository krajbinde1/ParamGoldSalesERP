<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->authorize(fn (): bool => ProductResource::canDelete($this->getRecord())),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('forceDelete', $this->getRecord()) ?? false),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false),
        ];
    }
}
