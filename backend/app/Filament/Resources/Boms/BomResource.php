<?php

namespace App\Filament\Resources\Boms;

use App\Enums\BomStatus;
use App\Filament\Concerns\InventoryFilamentAccess;
use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Filament\Resources\Boms\Pages\ViewBom;
use App\Filament\Resources\Boms\Schemas\BomForm;
use App\Filament\Resources\Boms\Schemas\BomInfolist;
use App\Filament\Resources\Boms\Tables\BomsTable;
use App\Models\Bom;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BomResource extends Resource
{
    use InventoryFilamentAccess;

    protected static ?string $model = Bom::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Bill of Materials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'bom_number';

    public static function form(Schema $schema): Schema
    {
        return BomForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BomInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBoms::route('/'),
            'create' => CreateBom::route('/create'),
            'view' => ViewBom::route('/{record}'),
            'edit' => EditBom::route('/{record}/edit'),
        ];
    }

    public static function isDirectorOrAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
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

    /**
     * Production supervisors (without director/admin access) may only view active BOMs.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['product:id,product_code,product_name'])
            ->withCount('items');

        $user = auth()->user();

        if ($user !== null && ! static::isDirectorOrAdmin()) {
            $query->where('status', BomStatus::Active->value);
        }

        return $query;
    }
}
