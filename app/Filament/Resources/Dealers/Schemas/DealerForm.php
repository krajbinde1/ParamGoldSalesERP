<?php

namespace App\Filament\Resources\Dealers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DealerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('dealer_code')
                    ->required(),
                TextInput::make('firm_name')
                    ->required(),
                TextInput::make('owner_name')
                    ->required(),
                TextInput::make('mobile')
                    ->required(),
                TextInput::make('alternate_mobile')
                    ->default(null),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->default(null),
                TextInput::make('gst_no')
                    ->default(null),
                Textarea::make('address')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('state')
                    ->required(),
                TextInput::make('district')
                    ->required(),
                TextInput::make('taluka')
                    ->required(),
                TextInput::make('village')
                    ->default(null),
                TextInput::make('pincode')
                    ->required(),
                TextInput::make('credit_limit')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('outstanding')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('latitude')
                    ->numeric()
                    ->default(null),
                TextInput::make('longitude')
                    ->numeric()
                    ->default(null),
                Toggle::make('status')
                    ->required(),
            ]);
    }
}
