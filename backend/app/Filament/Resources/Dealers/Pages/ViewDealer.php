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
            EditAction::make()
                ->authorize(fn (): bool => DealerResource::canEdit($this->getRecord())),
        ];
    }
}
