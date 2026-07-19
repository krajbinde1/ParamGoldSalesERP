<?php

namespace App\Filament\Resources\EmployeeRoutes;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\EmployeeRoutes\Pages\ListEmployeeRoutes;
use App\Filament\Resources\EmployeeRoutes\Pages\ViewEmployeeRoute;
use App\Filament\Resources\EmployeeRoutes\Tables\EmployeeRoutesTable;
use App\Models\Attendance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EmployeeRouteResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = Attendance::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Employee Routes';

    protected static ?string $modelLabel = 'Employee Route';

    protected static ?string $pluralModelLabel = 'Employee Routes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'attendance_date';

    public static function table(Table $table): Table
    {
        return EmployeeRoutesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeRoutes::route('/'),
            'view' => ViewEmployeeRoute::route('/{record}'),
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
