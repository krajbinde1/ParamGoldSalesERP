<?php

namespace App\Filament\Resources\FinishedProducts\Pages;

use App\Enums\StockItemType;
use App\Filament\Pages\StockItemLedger;
use App\Filament\Resources\FinishedProducts\FinishedProductResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFinishedProduct extends ViewRecord
{
    protected static string $resource = FinishedProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewLedger')
                ->label('View Ledger')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => StockItemLedger::urlForItem(
                    StockItemType::FinishedProduct->value,
                    (int) $this->getRecord()->getKey(),
                )),
            EditAction::make()
                ->authorize(fn (): bool => FinishedProductResource::canEdit($this->getRecord())),
        ];
    }
}
