<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAccordionNavigationParent;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\RawMaterialInwards\RawMaterialInwardResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Navigation parent only — accordion group for material inward.
 */
class MaterialInwardNav extends Page
{
    use HasAccordionNavigationParent;
    use InventoryFilamentAccess;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Material Inward';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $slug = 'material-inward';

    protected static ?string $title = 'Material Inward';

    protected string $view = 'filament.pages.inventory-nav-redirect';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->redirect(RawMaterialInwardResource::getUrl());
    }
}
