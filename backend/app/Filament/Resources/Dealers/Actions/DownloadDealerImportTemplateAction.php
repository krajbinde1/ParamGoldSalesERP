<?php

namespace App\Filament\Resources\Dealers\Actions;

use App\Services\Dealers\DealerBulkImportTemplate;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDealerImportTemplateAction
{
    public static function make(string $name = 'downloadDealerImportTemplate'): Action
    {
        return Action::make($name)
            ->label('Download Import Template')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(
                function (): void {
                    echo DealerBulkImportTemplate::csv();
                },
                'dealer-import-template.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            ));
    }
}
