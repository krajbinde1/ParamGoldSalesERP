<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vehicle Details')
                ->columns(2)
                ->schema([
                    TextInput::make('vehicle_number')
                        ->label('Vehicle Number')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (?string $state): string => \App\Models\Vehicle::normalizeVehicleNumber((string) $state)),
                    TextInput::make('vehicle_name')
                        ->label('Vehicle Name / Model')
                        ->maxLength(255),
                    TextInput::make('vehicle_type')
                        ->label('Vehicle Type')
                        ->maxLength(100),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }
}
