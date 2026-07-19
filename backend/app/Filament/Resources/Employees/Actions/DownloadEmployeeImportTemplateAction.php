<?php

namespace App\Filament\Resources\Employees\Actions;

use App\Exports\EmployeeImportTemplateExport;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadEmployeeImportTemplateAction
{
    public static function make(string $name = 'downloadEmployeeImportTemplate'): Action
    {
        return Action::make($name)
            ->label('Download Employee Excel Template')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (): BinaryFileResponse => Excel::download(
                new EmployeeImportTemplateExport,
                'employee-import-template.xlsx',
            ));
    }
}
