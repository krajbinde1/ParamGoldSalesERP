<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Legacy multi-module Inventory Import page.
 *
 * Fully disabled in navigation. Any direct URL redirects to Raw Material Import only.
 */
class InventoryBulkImport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationLabel = 'Inventory Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $slug = 'inventory-bulk-import';

    protected static ?string $title = 'Raw Material Import';

    protected string $view = 'filament.pages.inventory-nav-redirect';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageInventoryMasters();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->redirect(\App\Filament\Resources\RawMaterials\RawMaterialResource::getUrl('index'));
    }
}
