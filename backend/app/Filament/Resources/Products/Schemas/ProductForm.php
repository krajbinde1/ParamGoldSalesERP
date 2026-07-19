<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('product_code')
                            ->label('Product Code')
                            ->placeholder('Generated automatically when saved')
                            ->readOnly()
                            ->dehydrated(false),
                        TextInput::make('product_name')
                            ->required()
                            ->maxLength(255),
                        Select::make('uom')
                            ->label('UOM')
                            ->options([
                                'Bag' => 'Bag',
                                'Bottle' => 'Bottle',
                                'Box' => 'Box',
                                'Gram' => 'Gram',
                                'Kg' => 'Kg',
                                'Litre' => 'Litre',
                                'Millilitre' => 'Millilitre',
                                'Packet' => 'Packet',
                                'Piece' => 'Piece',
                            ])
                            ->searchable()
                            ->required(),
                        TextInput::make('nos_per_case')
                            ->label('Nos Per Case')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->integer(),
                        Select::make('gst_percentage')
                            ->label('GST %')
                            ->options([
                                '0' => '0%',
                                '5' => '5%',
                                '12' => '12%',
                                '18' => '18%',
                                '28' => '28%',
                            ])
                            ->default('0')
                            ->required(),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Pricing and inventory')
                    ->columns(2)
                    ->schema([
                        TextInput::make('dealer_price')
                            ->label('Dealer Price')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ]),
            ]);
    }
}
