<?php

namespace App\Filament\Resources\Products\Actions;

use App\Exports\ProductImportTemplateExport;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadProductImportTemplateAction
{
    public static function make(string $name = 'downloadProductImportTemplate'): Action
    {
        return Action::make($name)
            ->label('Download Excel Template')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (): BinaryFileResponse => Excel::download(
                new ProductImportTemplateExport,
                'product-import-template.xlsx',
            ));
    }
}
