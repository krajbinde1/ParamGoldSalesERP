<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\PurchaseStatus;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Filament\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\Resources\Purchases\Schemas\PurchaseInfolist;
use App\Filament\Resources\Purchases\Tables\PurchasesTable;
use App\Models\Purchase;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = Purchase::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Purchase';

    protected static ?string $modelLabel = 'Purchase';

    protected static ?string $pluralModelLabel = 'Purchases';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'purchase_number';

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchasesTable::configure($table);
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
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'view' => ViewPurchase::route('/{record}'),
            'edit' => EditPurchase::route('/{record}/edit'),
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

        return $user !== null && $user->canCreatePurchase();
    }

    public static function canEdit(Model $record): bool
    {
        $user = static::currentUser();

        return $user !== null && $user->can('update', $record);
    }

    public static function canSeeEditAction(Model $record): bool
    {
        $user = static::currentUser();
        if ($user === null) {
            return false;
        }

        if (! $record instanceof Purchase) {
            return false;
        }

        if ($record->isDraft()) {
            return $user->canCreatePurchase();
        }

        return $user->canUpdatePurchase() && $record->status === PurchaseStatus::Confirmed;
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
