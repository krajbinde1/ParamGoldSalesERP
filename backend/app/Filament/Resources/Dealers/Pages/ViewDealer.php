<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDealer extends ViewRecord
{
    protected static string $resource = DealerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewLedger')
                ->label('View Full Ledger')
                ->icon('heroicon-o-book-open')
                ->url(fn (): string => DealerResource::getUrl('ledger', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => auth()->user()?->can('viewLedger', $this->getRecord()) ?? false),
            Action::make('importTallyLedger')
                ->label('Import Tally Ledger')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn (): string => DealerResource::getUrl('import-tally-ledger', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => (
                    (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false)
                ) && ! $this->getRecord()->hasImportedTallyLedger()),
            EditAction::make()
                ->authorize(fn (): bool => DealerResource::canEdit($this->getRecord())),
        ];
    }
}
