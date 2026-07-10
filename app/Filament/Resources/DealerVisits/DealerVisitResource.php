<?php

namespace App\Filament\Resources\DealerVisits;

use App\Filament\Resources\DealerVisits\Pages\CreateDealerVisit;
use App\Filament\Resources\DealerVisits\Pages\EditDealerVisit;
use App\Filament\Resources\DealerVisits\Pages\ListDealerVisits;
use App\Filament\Resources\DealerVisits\Pages\ViewDealerVisit;
use App\Filament\Resources\DealerVisits\Schemas\DealerVisitForm;
use App\Filament\Resources\DealerVisits\Schemas\DealerVisitInfolist;
use App\Filament\Resources\DealerVisits\Tables\DealerVisitsTable;
use App\Models\DealerVisit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DealerVisitResource extends Resource
{
    protected static ?string $model = DealerVisit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DealerVisitForm::configure($schema);
    }

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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealerVisits::route('/'),
            'create' => CreateDealerVisit::route('/create'),
            'view' => ViewDealerVisit::route('/{record}'),
            'edit' => EditDealerVisit::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
