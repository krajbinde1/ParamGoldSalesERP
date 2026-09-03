<?php

namespace App\Filament\Resources\Targets;

use App\Filament\Concerns\DeniesOrdersOnlyFilamentUsers;
use App\Filament\Resources\Targets\Pages\CreateWeeklyTarget;
use App\Filament\Resources\Targets\Pages\EditWeeklyTarget;
use App\Filament\Resources\Targets\Pages\ListWeeklyTargets;
use App\Filament\Resources\Targets\Pages\ViewWeeklyTarget;
use App\Filament\Resources\Targets\Schemas\WeeklyTargetForm;
use App\Filament\Resources\Targets\Schemas\WeeklyTargetInfolist;
use App\Filament\Resources\Targets\Tables\WeeklyTargetsTable;
use App\Models\WeeklyTarget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WeeklyTargetResource extends Resource
{
    use DeniesOrdersOnlyFilamentUsers;

    protected static ?string $model = WeeklyTarget::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Targets';

    protected static ?string $modelLabel = 'Target';

    protected static ?string $pluralModelLabel = 'Targets';

    protected static ?string $recordTitleAttribute = 'week_start_date';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee', 'monthlyTarget']);
    }

    public static function form(Schema $schema): Schema
    {
        return WeeklyTargetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WeeklyTargetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WeeklyTargetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWeeklyTargets::route('/'),
            'create' => CreateWeeklyTarget::route('/create'),
            'view' => ViewWeeklyTarget::route('/{record}'),
            'edit' => EditWeeklyTarget::route('/{record}/edit'),
        ];
    }
}
