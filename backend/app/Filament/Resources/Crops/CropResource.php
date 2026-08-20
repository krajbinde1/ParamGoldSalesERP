<?php

namespace App\Filament\Resources\Crops;

use App\Filament\Resources\Crops\Pages\CreateCrop;
use App\Filament\Resources\Crops\Pages\EditCrop;
use App\Filament\Resources\Crops\Pages\ListCrops;
use App\Filament\Resources\Crops\Schemas\CropForm;
use App\Filament\Resources\Crops\Tables\CropsTable;
use App\Models\Crop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CropResource extends Resource
{
    protected static ?string $model = Crop::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales Operations';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Crops';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->usesAdminDirectorDashboard() || $user->isAdminUser());
    }

    public static function form(Schema $schema): Schema
    {
        return CropForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CropsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrops::route('/'),
            'create' => CreateCrop::route('/create'),
            'edit' => EditCrop::route('/{record}/edit'),
        ];
    }
}
