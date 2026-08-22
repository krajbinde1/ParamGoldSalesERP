<?php

namespace App\Filament\Resources\Dealers;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\Dealers\Pages\CreateDealer;
use App\Filament\Resources\Dealers\Pages\EditDealer;
use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Filament\Resources\Dealers\Pages\ViewDealer;
use App\Filament\Resources\Dealers\Pages\ViewDealerLedger;
use App\Filament\Resources\Dealers\Schemas\DealerForm;
use App\Filament\Resources\Dealers\Schemas\DealerInfolist;
use App\Filament\Resources\Dealers\Tables\DealersTable;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerLedgerService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DealerResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = Dealer::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'firm_name';

    public static function form(Schema $schema): Schema
    {
        return DealerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DealerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealersTable::configure($table);
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
            'index' => ListDealers::route('/'),
            'create' => CreateDealer::route('/create'),
            'view' => ViewDealer::route('/{record}'),
            'ledger' => ViewDealerLedger::route('/{record}/ledger'),
            'edit' => EditDealer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user !== null) {
            app(DealerAccessService::class)->scopeVisibleTo($query, $user);
        }

        return app(DealerLedgerService::class)->scopeWithCurrentOutstanding($query);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
