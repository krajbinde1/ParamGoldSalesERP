<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\Actions\DownloadDealerImportTemplateAction;
use App\Filament\Resources\Dealers\Actions\ImportDealersAction;
use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListDealers extends ListRecords
{
    protected static string $resource = DealerResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkImportTallyLedger')
                ->label('Bulk Tally Ledger Import')
                ->url(DealerResource::getUrl('bulk-import-tally-ledger'))
                ->visible(fn (): bool => (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false)),
            Action::make('tallyImportHistory')
                ->label('Tally Import History')
                ->url(DealerResource::getUrl('tally-import-history'))
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->isAdminUser() ?? false) || (auth()->user()?->isDirectorUser() ?? false)),
            DownloadDealerImportTemplateAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            ImportDealersAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            CreateAction::make()
                ->authorize(fn (): bool => DealerResource::canCreate()),
        ];
    }
}
