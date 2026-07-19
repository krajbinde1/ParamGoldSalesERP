<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\Actions\DownloadEmployeeImportTemplateAction;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadEmployeeImportTemplateAction::make()
                ->visible(fn (): bool => EmployeeResource::canCreate()),
            Action::make('bulkUploadEmployees')
                ->label('Bulk Upload Employees')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->url(fn (): string => EmployeeResource::getUrl('bulk-upload'))
                ->visible(fn (): bool => EmployeeResource::canCreate()),
            CreateAction::make()
                ->authorize(fn (): bool => EmployeeResource::canCreate()),
        ];
    }
}
