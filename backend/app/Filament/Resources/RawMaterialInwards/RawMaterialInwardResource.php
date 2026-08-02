<?php

namespace App\Filament\Resources\RawMaterialInwards;

use App\Enums\RawMaterialInwardStatus;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\RawMaterialInwards\Pages\CreateRawMaterialInward;
use App\Filament\Resources\RawMaterialInwards\Pages\EditRawMaterialInward;
use App\Filament\Resources\RawMaterialInwards\Pages\ListRawMaterialInwards;
use App\Filament\Resources\RawMaterialInwards\Pages\ViewRawMaterialInward;
use App\Filament\Resources\RawMaterialInwards\Schemas\RawMaterialInwardForm;
use App\Filament\Resources\RawMaterialInwards\Schemas\RawMaterialInwardInfolist;
use App\Filament\Resources\RawMaterialInwards\Tables\RawMaterialInwardsTable;
use App\Models\RawMaterialInward;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RawMaterialInwardResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = RawMaterialInward::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Material Inward';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Raw Material Inward';

    protected static ?string $modelLabel = 'Raw Material Inward';

    protected static ?string $pluralModelLabel = 'Raw Material Inwards';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $recordTitleAttribute = 'inward_number';

    public static function form(Schema $schema): Schema
    {
        return RawMaterialInwardForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RawMaterialInwardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RawMaterialInwardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        // List/view index only needs header relations — never hydrate items/ledgers here.
        return parent::getEloquentQuery()->with([
            'supplier:id,supplier_name',
            'createdBy:id,name',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRawMaterialInwards::route('/'),
            'create' => CreateRawMaterialInward::route('/create'),
            'view' => ViewRawMaterialInward::route('/{record}'),
            'edit' => EditRawMaterialInward::route('/{record}/edit'),
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
        $user = static::currentUser();

        return $user !== null && $user->can('update', $record);
    }

    /**
     * Whether the user may see the Edit row action (enabled or disabled for locked posted).
     */
    public static function canSeeEditAction(Model $record): bool
    {
        $user = static::currentUser();
        if ($user === null || ! $user->canUpdateRawMaterialInward()) {
            return false;
        }

        if (! $record instanceof RawMaterialInward) {
            return false;
        }

        return $record->isEditable()
            || $record->status === RawMaterialInwardStatus::Posted;
    }

    public static function editLockTooltip(Model $record): ?string
    {
        if (! $record instanceof RawMaterialInward) {
            return null;
        }

        if (static::canEdit($record)) {
            return null;
        }

        if ($record->status === RawMaterialInwardStatus::Posted
            && $record->hasSubsequentStockTransactions()) {
            return 'Cannot edit because subsequent stock transactions exist.';
        }

        return 'This inward cannot be edited.';
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
