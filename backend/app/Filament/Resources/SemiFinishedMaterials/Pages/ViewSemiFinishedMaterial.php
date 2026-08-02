<?php

namespace App\Filament\Resources\SemiFinishedMaterials\Pages;

use App\Enums\StockItemType;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\SemiFinishedMaterials\SemiFinishedMaterialResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSemiFinishedMaterial extends ViewRecord
{
    protected static string $resource = SemiFinishedMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewLedger')
                ->label('View Ledger')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => StockItemLedger::urlForItem(
                    StockItemType::SemiFinished->value,
                    (int) $this->getRecord()->getKey(),
                )),
            EditAction::make()
                ->authorize(fn (): bool => SemiFinishedMaterialResource::canEdit($this->getRecord())),
        ];
    }
}
