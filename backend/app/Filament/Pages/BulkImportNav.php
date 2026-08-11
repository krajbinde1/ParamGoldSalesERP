<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAccordionNavigationParent;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Navigation parent only — accordion group for inventory bulk upload imports.
 */
class BulkImportNav extends Page
{
    use HasAccordionNavigationParent;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Bulk Upload';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $slug = 'bulk-import';

    protected static ?string $title = 'Bulk Upload';

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
