<?php

namespace App\Filament\Resources\Dealers\Actions;

use App\Services\Dealers\DealerBulkImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;

class ImportDealersAction
{
    public static function make(string $name = 'importDealers'): Action
    {
        return Action::make($name)
            ->label('Bulk Import')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading('Import Dealers')
            ->modalDescription('Upload the dealer import template CSV. Mandatory columns must be filled for each row. Valid rows are imported even when some rows fail.')
            ->form([
                FileUpload::make('file')
                    ->label('CSV File')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ])
                    ->required()
                    ->storeFiles(false),
            ])
            ->action(function (array $data): void {
                $uploaded = $data['file'];

                if ($uploaded === null) {
                    Notification::make()
                        ->danger()
                        ->title('Import failed')
                        ->body('Please choose a CSV file to import.')
                        ->send();

                    return;
                }

                $path = $uploaded->getRealPath();

                if ($path === false) {
                    Notification::make()
                        ->danger()
                        ->title('Import failed')
                        ->body('Unable to read the uploaded file.')
                        ->send();

                    return;
                }

                $result = app(DealerBulkImportService::class)->import($path);

                if ($result->imported === 0 && $result->failed() > 0) {
                    Notification::make()
                        ->danger()
                        ->title('No dealers imported')
                        ->body(self::formatErrors($result))
                        ->persistent()
                        ->send();

                    return;
                }

                $body = "{$result->imported} dealer(s) imported successfully.";

                if ($result->failed() > 0) {
                    $body .= "\n\n{$result->failed()} row(s) failed:\n".self::formatErrors($result);
                }

                Notification::make()
                    ->success()
                    ->title('Dealer import completed')
                    ->body($body)
                    ->persistent($result->failed() > 0)
                    ->send();
            });
    }

    private static function formatErrors(\App\Services\Dealers\DealerBulkImportResult $result): string
    {
        return collect($result->errors)
            ->take(20)
            ->map(fn ($error): string => "Row {$error->rowNumber}: {$error->firmName} [{$error->assignedEmployeeCode}] — {$error->reason}")
            ->implode("\n");
    }
}
