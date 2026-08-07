<?php

namespace App\Filament\Resources\FinishedProducts;

use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\FinishedProducts\Pages\CreateFinishedProduct;
use App\Filament\Resources\FinishedProducts\Pages\EditFinishedProduct;
use App\Filament\Resources\FinishedProducts\Pages\ListFinishedProducts;
use App\Filament\Resources\FinishedProducts\Pages\ViewFinishedProduct;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductForm;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductInfolist;
use App\Filament\Resources\FinishedProducts\Tables\FinishedProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finished Goods Inventory — Filament resource over Sales Products.
 *
 * Sales Operations → Products is the single product master. This resource manages
 * FG inventory fields on the same Product rows (stock, WAC, min stock). The
 * finished_products sidecar remains optional for legacy FP codes.
 */
class FinishedProductResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = Product::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Material Masters';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Finished Goods Inventory';

    protected static ?string $modelLabel = 'Finished Goods Inventory';

    protected static ?string $pluralModelLabel = 'Finished Goods Inventory';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'product_name';

    protected static ?string $slug = 'finished-products';

    public static function form(Schema $schema): Schema
    {
        return FinishedProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FinishedProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinishedProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    /**
     * All Sales Products (SoftDeletes default) — same source as Sales Operations → Products.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('finishedProduct');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinishedProducts::route('/'),
            'create' => CreateFinishedProduct::route('/create'),
            'view' => ViewFinishedProduct::route('/{record}'),
            'edit' => EditFinishedProduct::route('/{record}/edit'),
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
