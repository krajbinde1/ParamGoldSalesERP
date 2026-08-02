<?php

namespace App\Filament\Resources\PackagingMaterials;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\PackagingMaterials\Pages\CreatePackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Pages\EditPackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Pages\ListPackagingMaterials;
use App\Filament\Resources\PackagingMaterials\Pages\ViewPackagingMaterial;
use App\Filament\Resources\PackagingMaterials\Schemas\PackagingMaterialForm;
use App\Filament\Resources\PackagingMaterials\Schemas\PackagingMaterialInfolist;
use App\Filament\Resources\PackagingMaterials\Tables\PackagingMaterialsTable;
use App\Models\PackagingMaterial;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PackagingMaterialResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = PackagingMaterial::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Material Masters';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Packaging Material Master';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'packaging_name';

    protected static ?string $modelLabel = 'Packaging Material';

    protected static ?string $pluralModelLabel = 'Packaging Materials';

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            'Bags' => 'Bags',
            'Bottles' => 'Bottles',
            'Boxes' => 'Boxes',
            'Labels' => 'Labels',
            'Caps' => 'Caps',
            'Cartons' => 'Cartons',
            'Pouches' => 'Pouches',
            'Other' => 'Other',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return PackagingMaterialForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PackagingMaterialInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackagingMaterialsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Master list/query is strictly the PackagingMaterial model/table only —
     * never mixes raw materials, semi-finished, finished, or other item types.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackagingMaterials::route('/'),
            'create' => CreatePackagingMaterial::route('/create'),
            'view' => ViewPackagingMaterial::route('/{record}'),
            'edit' => EditPackagingMaterial::route('/{record}/edit'),
        ];
    }

    public static function isDirectorOrAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    public static function canViewPurchaseRates(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->usesAdminDirectorDashboard()
            || $user->isAdminUser()
            || $user->isManagerUser();
    }

    public static function canCreate(): bool
    {
        return static::isDirectorOrAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return static::isDirectorOrAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return static::isDirectorOrAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return static::isDirectorOrAdmin();
    }
}
