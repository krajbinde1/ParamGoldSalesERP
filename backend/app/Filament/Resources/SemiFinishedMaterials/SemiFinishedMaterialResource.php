<?php

namespace App\Filament\Resources\SemiFinishedMaterials;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\SemiFinishedMaterials\Pages\CreateSemiFinishedMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Pages\EditSemiFinishedMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ListSemiFinishedMaterials;
use App\Filament\Resources\SemiFinishedMaterials\Pages\ViewSemiFinishedMaterial;
use App\Filament\Resources\SemiFinishedMaterials\Schemas\SemiFinishedMaterialForm;
use App\Filament\Resources\SemiFinishedMaterials\Schemas\SemiFinishedMaterialInfolist;
use App\Filament\Resources\SemiFinishedMaterials\Tables\SemiFinishedMaterialsTable;
use App\Models\SemiFinishedMaterial;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SemiFinishedMaterialResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = SemiFinishedMaterial::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Material Masters';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Semi-Finished Master';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'material_name';

    public static function form(Schema $schema): Schema
    {
        return SemiFinishedMaterialForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SemiFinishedMaterialInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SemiFinishedMaterialsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSemiFinishedMaterials::route('/'),
            'create' => CreateSemiFinishedMaterial::route('/create'),
            'view' => ViewSemiFinishedMaterial::route('/{record}'),
            'edit' => EditSemiFinishedMaterial::route('/{record}/edit'),
        ];
    }

    public static function isDirectorOrAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    public static function canViewCosts(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->canViewProductionCosts();
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
