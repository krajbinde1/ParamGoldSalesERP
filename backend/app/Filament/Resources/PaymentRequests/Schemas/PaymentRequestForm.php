<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment Request')
                    ->columns(2)
                    ->schema([
                        TextInput::make('vendor_name')
                            ->label('Vendor Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                        TextInput::make('vendor_mobile')
                            ->label('Vendor Mobile Number')
                            ->required()
                            ->tel()
                            ->maxLength(20)
                            ->rules(['regex:/^[0-9+\-\s]{8,20}$/'])
                            ->columnSpan(1),
                        TextInput::make('amount')
                            ->label('Amount ₹')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('₹')
                            ->columnSpan(1),
                        Textarea::make('remark')
                            ->label('Remark')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
