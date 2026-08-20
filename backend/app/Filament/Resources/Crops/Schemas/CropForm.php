<?php

namespace App\Filament\Resources\Crops\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CropForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0),
                Toggle::make('status')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
