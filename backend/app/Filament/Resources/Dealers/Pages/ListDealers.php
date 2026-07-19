<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\Actions\DownloadDealerImportTemplateAction;
use App\Filament\Resources\Dealers\Actions\ImportDealersAction;
use App\Filament\Resources\Dealers\DealerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDealers extends ListRecords
{
    protected static string $resource = DealerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadDealerImportTemplateAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            ImportDealersAction::make()
                ->visible(fn (): bool => DealerResource::canCreate()),
            CreateAction::make()
                ->authorize(fn (): bool => DealerResource::canCreate()),
        ];
    }
}
