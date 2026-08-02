<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAccordionNavigationParent;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\RawMaterials\RawMaterialResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Navigation parent only — accordion group for material masters.
 */
class MaterialMastersNav extends Page
{
    use HasAccordionNavigationParent;
    use InventoryFilamentAccess;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Material Masters';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $slug = 'material-masters';

    protected static ?string $title = 'Material Masters';

    protected string $view = 'filament.pages.inventory-nav-redirect';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->redirect(RawMaterialResource::getUrl());
    }
}
