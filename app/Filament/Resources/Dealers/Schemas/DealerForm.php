<?php

namespace App\Filament\Resources\Dealers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DealerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dealer details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('dealer_code')
                            ->label('Dealer Code')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('firm_name')
                            ->label('Firm Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_name')
                            ->label('Owner Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('dealer_type')
                            ->options([
                                'Distributor' => 'Distributor',
                                'Retailer' => 'Retailer',
                                'Wholesaler' => 'Wholesaler',
                            ])
                            ->default('Retailer')
                            ->required(),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Contact and compliance')
                    ->columns(2)
                    ->schema([
                        TextInput::make('mobile')
                            ->tel()
                            ->required()
                            ->rule('regex:/^[6-9][0-9]{9}$/')
                            ->validationMessages(['regex' => 'Enter a valid 10-digit Indian mobile number.'])
                            ->maxLength(10),
                        TextInput::make('whatsapp')
                            ->label('WhatsApp Number')
                            ->tel()
                            ->rule('regex:/^[6-9][0-9]{9}$/')
                            ->validationMessages(['regex' => 'Enter a valid 10-digit Indian mobile number.'])
                            ->maxLength(10),
                        TextInput::make('alternate_mobile')
                            ->label('Alternate Mobile')
                            ->tel()
                            ->rule('regex:/^[6-9][0-9]{9}$/')
                            ->validationMessages(['regex' => 'Enter a valid 10-digit Indian mobile number.'])
                            ->maxLength(10),
                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('gst_no')
                            ->label('GST Number')
                            ->uppercase()
                            ->rule('regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/')
                            ->validationMessages(['regex' => 'Enter a valid GSTIN.'])
                            ->maxLength(15),
                        TextInput::make('pan_no')
                            ->label('PAN Number')
                            ->uppercase()
                            ->rule('regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/')
                            ->validationMessages(['regex' => 'Enter a valid PAN.'])
                            ->maxLength(10),
                        TextInput::make('fertilizer_license_no')
                            ->label('Fertilizer License Number')
                            ->maxLength(255),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        Textarea::make('address')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('state')->required()->maxLength(255),
                        TextInput::make('district')->required()->maxLength(255),
                        TextInput::make('taluka')->required()->maxLength(255),
                        TextInput::make('village')->maxLength(255),
                        TextInput::make('pincode')
                            ->required()
                            ->rule('regex:/^[1-9][0-9]{5}$/')
                            ->validationMessages(['regex' => 'Enter a valid 6-digit PIN code.'])
                            ->maxLength(6),
                    ]),
                Section::make('Account and location')
                    ->columns(2)
                    ->schema([
                        TextInput::make('credit_limit')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('outstanding')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
                    ]),
            ]);
    }
}
