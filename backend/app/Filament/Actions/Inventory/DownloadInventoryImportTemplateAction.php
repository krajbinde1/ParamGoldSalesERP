<?php

namespace App\Filament\Actions\Inventory;

use App\Enums\InventoryBulkImportType;
use App\Exports\Inventory\FinishedGoodsOpeningStockTemplateExport;
use App\Exports\Inventory\InventoryBulkImportTemplateExport;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DownloadInventoryImportTemplateAction
{
    public static function make(
        InventoryBulkImportType $type,
        string $label = 'Download Template',
        string $name = 'downloadInventoryImportTemplate',
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => self::canManage())
            ->action(fn (): BinaryFileResponse => self::download($type));
    }

    public static function download(InventoryBulkImportType $type): BinaryFileResponse
    {
        if ($type === InventoryBulkImportType::FinishedGoodsOpeningStock) {
            return Excel::download(
                new FinishedGoodsOpeningStockTemplateExport,
                InventoryBulkImportTemplate::downloadFilename($type),
            );
        }

        return Excel::download(
            new InventoryBulkImportTemplateExport($type),
            InventoryBulkImportTemplate::downloadFilename($type),
        );
    }

    private static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageInventoryMasters();
    }
}
