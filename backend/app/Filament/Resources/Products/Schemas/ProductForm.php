<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\InventoryUnit;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                Section::make('Manufacturing')
                    ->description('Enable manufacturing to link this product with a Bill of Materials and produce it through the Inventory & Manufacturing module.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('manufacturing_enabled')
                            ->label('Manufacturing Enabled')
                            ->live()
                            ->inline(false)
                            ->columnSpanFull(),
                        Select::make('production_unit')
                            ->label('Production Unit')
                            ->options(InventoryUnit::options())
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        TextInput::make('standard_batch_size')
                            ->label('Standard Batch Size')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        TextInput::make('minimum_finished_stock')
                            ->label('Minimum Finished Stock')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        TextInput::make('current_finished_stock')
                            ->label('Current Finished Stock')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled'))
                            ->helperText('Managed automatically by the production and stock adjustment workflows.'),
                        TextInput::make('shelf_life_days')
                            ->label('Shelf Life (Days)')
                            ->numeric()
                            ->minValue(0)
                            ->integer()
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        Toggle::make('batch_tracking_enabled')
                            ->label('Batch Tracking')
                            ->default(true)
                            ->inline(false)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        TextInput::make('standard_production_cost')
                            ->label('Standard Production Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled')),
                        TextInput::make('weighted_average_cost')
                            ->label('Weighted Average Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled'))
                            ->helperText('Recalculated automatically after every completed production batch.'),
                    ]),
                Section::make('Pack sizes / packing variants')
                    ->description('Define pack-wise manufacturing variants for this product. BOMs and finished goods stock are tracked per variant.')
                    ->visible(fn (Get $get): bool => (bool) $get('manufacturing_enabled'))
                    ->schema([
                        Repeater::make('variants')
                            ->relationship()
                            ->defaultItems(0)
                            ->addActionLabel('Add pack variant')
                            ->columns(3)
                            ->schema([
                                TextInput::make('variant_code')
                                    ->label('Variant Code')
                                    ->maxLength(40)
                                    ->helperText('Leave blank to auto-generate.'),
                                TextInput::make('pack_size')
                                    ->label('Pack Size')
                                    ->numeric()
                                    ->minValue(0.0001)
                                    ->required(),
                                Select::make('pack_unit')
                                    ->label('Pack Unit')
                                    ->options(InventoryUnit::options())
                                    ->required()
                                    ->default(InventoryUnit::Kg->value),
                                TextInput::make('packaging_type')
                                    ->label('Packaging Type')
                                    ->maxLength(50),
                                TextInput::make('units_per_case')
                                    ->label('Units Per Case')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1),
                                TextInput::make('net_weight')
                                    ->label('Net Weight')
                                    ->numeric()
                                    ->minValue(0),
                                Toggle::make('is_bulk')
                                    ->label('Bulk')
                                    ->inline(false),
                                Toggle::make('manufacturing_enabled')
                                    ->label('Manufacturing Enabled')
                                    ->default(true)
                                    ->inline(false),
                                Toggle::make('status')
                                    ->label('Active')
                                    ->default(true)
                                    ->inline(false),
                                TextInput::make('current_stock')
                                    ->label('Current Pack Stock')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ]),
            ]);
    }
}
