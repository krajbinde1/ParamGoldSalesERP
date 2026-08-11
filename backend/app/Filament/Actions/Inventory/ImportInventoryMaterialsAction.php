<?php

namespace App\Filament\Actions\Inventory;

use App\Enums\InventoryBulkImportType;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportManager;
use App\Services\Inventory\BulkImport\InventoryBulkImportResult;
use App\Services\Inventory\BulkImport\InventoryBulkImportRowError;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ImportInventoryMaterialsAction
{
    public static function make(
        InventoryBulkImportType $type,
        string $label = 'Import Excel',
        string $name = 'importInventoryMaterials',
        ?string $modalHeading = null,
        ?string $modalDescription = null,
    ): Action {
        $heading = $modalHeading ?? $type->label();
        $description = $modalDescription ?? $type->description();

        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->visible(fn (): bool => self::canManage())
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitActionLabel('Import')
            ->form([
                FileUpload::make('file')
                    ->label('Select Excel File')
                    ->helperText('Supported formats: .xlsx, .xls, .csv. Download the template first for the correct columns.')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required()
                    ->storeFiles(false),
            ])
            ->extraModalFooterActions(fn (Action $action): array => [
                DownloadInventoryImportTemplateAction::make(
                    type: $type,
                    label: self::templateFooterLabel($type),
                    name: $name.'DownloadTemplate',
                )
                    ->color('gray')
                    ->cancelParentActions(),
            ])
            ->action(function (array $data) use ($type): void {
                self::runImport($type, $data['file'] ?? null);
            })
            ->successNotification(null)
            ->failureNotification(null);
    }

    private static function runImport(InventoryBulkImportType $type, mixed $uploaded): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->canManageInventoryMasters()) {
            Notification::make()
                ->danger()
                ->title('Import blocked')
                ->body('You do not have permission to import inventory materials.')
                ->send();

            return;
        }

        if (! $uploaded instanceof TemporaryUploadedFile) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body('Please choose an Excel file to import.')
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

        try {
            $result = app(InventoryBulkImportManager::class)->import($path, $type, $user);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Import blocked')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to import.')
                ->persistent()
                ->send();

            return;
        }

        self::notifyResult($type, $result);
    }

    private static function notifyResult(InventoryBulkImportType $type, InventoryBulkImportResult $result): void
    {
        $title = $type->cardTitle().' import completed';
        $body = "Imported: {$result->imported} | Failed: {$result->failed}";

        if ($result->stockUpdated > 0) {
            $body .= " | Stock updated: {$result->stockUpdated}";
        }

        if ($result->openingLedgerCreated > 0) {
            $body .= " | Opening ledger: {$result->openingLedgerCreated}";
        }

        if ($result->failed > 0) {
            $body .= "\n\n".self::formatErrors($result);
        }

        if ($result->imported === 0 && $result->failed > 0) {
            Notification::make()
                ->danger()
                ->title('No rows imported')
                ->body($body)
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($title)
            ->body($body)
            ->persistent($result->failed > 0)
            ->send();
    }

    private static function formatErrors(InventoryBulkImportResult $result): string
    {
        return collect($result->errors)
            ->take(20)
            ->map(function (InventoryBulkImportRowError $error): string {
                $name = (string) (
                    $error->rowData['material_name']
                    ?? $error->rowData['packaging_name']
                    ?? $error->rowData['product_name']
                    ?? $error->rowData['product_code']
                    ?? ''
                );

                $prefix = $name !== '' ? " {$name} —" : '';

                return "Row {$error->rowNumber}:{$prefix} {$error->reason}";
            })
            ->implode("\n");
    }

    private static function templateFooterLabel(InventoryBulkImportType $type): string
    {
        return match ($type) {
            InventoryBulkImportType::FinishedGoodsOpeningStock => 'Download Opening Stock Template',
            default => 'Download Template',
        };
    }

    private static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageInventoryMasters();
    }
}
