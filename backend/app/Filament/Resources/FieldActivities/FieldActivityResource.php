<?php

namespace App\Filament\Resources\FieldActivities;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\FieldActivities\Pages\ListFieldActivities;
use App\Filament\Resources\FieldActivities\Pages\ViewFieldActivity;
use App\Filament\Resources\FieldActivities\Schemas\FieldActivityInfolist;
use App\Filament\Resources\FieldActivities\Tables\FieldActivitiesTable;
use App\Models\FieldActivity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FieldActivityResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = FieldActivity::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Field Activities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'farmer_name';

    public static function infolist(Schema $schema): Schema
    {
        return FieldActivityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FieldActivitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFieldActivities::route('/'),
            'view' => ViewFieldActivity::route('/{record}'),
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
