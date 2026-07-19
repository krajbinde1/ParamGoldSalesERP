<?php

namespace App\Filament\Resources\DealerVisits;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\DealerVisits\Pages\ListDealerVisits;
use App\Filament\Resources\DealerVisits\Pages\ViewDealerVisit;
use App\Filament\Resources\DealerVisits\Schemas\DealerVisitInfolist;
use App\Filament\Resources\DealerVisits\Tables\DealerVisitsTable;
use App\Models\DealerVisit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DealerVisitResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = DealerVisit::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Dealer Visits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return DealerVisitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealerVisitsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealerVisits::route('/'),
            'view' => ViewDealerVisit::route('/{record}'),
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
