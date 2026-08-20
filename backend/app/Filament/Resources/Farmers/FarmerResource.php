<?php

namespace App\Filament\Resources\Farmers;

use App\Filament\Resources\Farmers\Pages\ListFarmers;
use App\Filament\Resources\Farmers\Pages\ViewFarmer;
use App\Filament\Resources\Farmers\Schemas\FarmerInfolist;
use App\Filament\Resources\Farmers\Tables\FarmersTable;
use App\Filament\Resources\Farmers\Widgets\FarmerStats;
use App\Models\Farmer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FarmerResource extends Resource
{
    protected static ?string $model = Farmer::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Employee Management';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Farmers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    public static function infolist(Schema $schema): Schema
    {
        return FarmerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FarmersTable::configure($table);
    }

    public static function getWidgets(): array
    {
        return [
            FarmerStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFarmers::route('/'),
            'view' => ViewFarmer::route('/{record}'),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['district', 'taluka', 'createdByEmployee', 'latestActivity.crop'])
            ->withCount('fieldActivities');
    }
}
