<?php

namespace App\Filament\Resources\PackagingMaterialInwards;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\PackagingMaterialInwards\Pages\CreatePackagingMaterialInward;
use App\Filament\Resources\PackagingMaterialInwards\Pages\EditPackagingMaterialInward;
use App\Filament\Resources\PackagingMaterialInwards\Pages\ListPackagingMaterialInwards;
use App\Filament\Resources\PackagingMaterialInwards\Pages\ViewPackagingMaterialInward;
use App\Filament\Resources\PackagingMaterialInwards\Schemas\PackagingMaterialInwardForm;
use App\Filament\Resources\PackagingMaterialInwards\Schemas\PackagingMaterialInwardInfolist;
use App\Filament\Resources\PackagingMaterialInwards\Tables\PackagingMaterialInwardsTable;
use App\Models\PackagingMaterialInward;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PackagingMaterialInwardResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = PackagingMaterialInward::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Material Inward';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Packaging Material Inward';

    protected static ?string $modelLabel = 'Packaging Material Inward';

    protected static ?string $pluralModelLabel = 'Packaging Material Inwards';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'inward_number';

    public static function form(Schema $schema): Schema
    {
        return PackagingMaterialInwardForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PackagingMaterialInwardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackagingMaterialInwardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'supplier:id,supplier_name',
            'createdBy:id,name',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackagingMaterialInwards::route('/'),
            'create' => CreatePackagingMaterialInward::route('/create'),
            'view' => ViewPackagingMaterialInward::route('/{record}'),
            'edit' => EditPackagingMaterialInward::route('/{record}/edit'),
        ];
    }

    public static function currentUser(): ?User
    {
        return auth()->user();
    }

    public static function canViewRates(): bool
    {
        $user = static::currentUser();

        return $user !== null && $user->canViewInwardRates();
    }

    public static function canCreate(): bool
    {
        $user = static::currentUser();

        return $user !== null && $user->canCreateRawMaterialInward();
    }

    public static function canEdit(Model $record): bool
    {
        // Posted inwards are immutable; create always posts immediately.
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
