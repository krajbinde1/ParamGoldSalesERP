<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Actions\DownloadProductImportTemplateAction;
use App\Filament\Resources\Products\Actions\DownloadProductListExcelAction;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadProductListExcelAction::make(),
            DownloadProductImportTemplateAction::make()
                ->visible(fn (): bool => ProductResource::canCreate()),
            Action::make('bulkUploadProducts')
                ->label('Bulk Upload Products')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->url(fn (): string => ProductResource::getUrl('bulk-upload'))
                ->visible(fn (): bool => ProductResource::canCreate()),
            CreateAction::make()
                ->authorize(fn (): bool => ProductResource::canCreate()),
        ];
    }
}
