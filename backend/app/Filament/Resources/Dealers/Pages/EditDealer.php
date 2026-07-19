<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDealer extends EditRecord
{
    protected static string $resource = DealerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->authorize(fn (): bool => DealerResource::canDelete($this->getRecord())),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('forceDelete', $this->getRecord()) ?? false),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('restore', $this->getRecord()) ?? false),
        ];
    }
}
