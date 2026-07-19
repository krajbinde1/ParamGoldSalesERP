<?php

namespace App\Filament\Resources\TaDaClaims;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\TaDaClaims\Pages\ListTaDaClaims;
use App\Filament\Resources\TaDaClaims\Pages\ViewTaDaClaim;
use App\Filament\Resources\TaDaClaims\Schemas\TaDaClaimInfolist;
use App\Filament\Resources\TaDaClaims\Tables\TaDaClaimsTable;
use App\Models\TaDaClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TaDaClaimResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = TaDaClaim::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'TA/DA Claims';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return TaDaClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaDaClaimsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaDaClaims::route('/'),
            'view' => ViewTaDaClaim::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
