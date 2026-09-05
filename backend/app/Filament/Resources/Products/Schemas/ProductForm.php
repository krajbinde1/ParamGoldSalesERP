<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\InventoryUnit;
use App\Filament\Resources\FinishedProducts\Schemas\FinishedProductForm;
use App\Models\Product;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                            ->integer()
                            ->live(debounce: 300)
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                FinishedProductForm::recalculateOpeningDerivedFields($get, $set);
                            }),
                        Select::make('gst_percentage')
                            ->label('GST %')
                            ->options(self::gstOptions())
                            ->formatStateUsing(fn (mixed $state): ?string => self::normalizeGstKey($state))
                            ->afterStateHydrated(function (Select $component, mixed $state): void {
                                $normalized = self::normalizeGstKey($state);
                                if ($normalized !== null) {
                                    $component->state($normalized);
                                }
                            })
                            ->dehydrateStateUsing(fn (mixed $state): mixed => filled($state)
                                ? (int) $state
                                : $state)
                            ->default('0')
                            ->required(),
                        Toggle::make('status')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
                ...FinishedProductForm::openingStockComponents(
                    readOnly: false,
                    includeNosPerCase: false,
                ),
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
                    ->description('Current finished stock and weighted average cost are calculated from inventory transactions. Manufacturing is treated as enabled automatically when this product has an Active BOM.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('minimum_finished_stock')
                            ->label('Minimum Finished Stock')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('current_finished_stock')
                            ->label('Current Finished Stock')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Calculated automatically from inventory transactions.'),
                        TextInput::make('shelf_life_days')
                            ->label('Shelf Life (Days)')
                            ->numeric()
                            ->minValue(0)
                            ->integer(),
                        Toggle::make('batch_tracking_enabled')
                            ->label('Batch Tracking')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('weighted_average_cost')
                            ->label('Weighted Average Cost')
                            ->prefix('₹')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Calculated automatically from inventory transactions.'),
                    ]),
                Section::make('Pack sizes / packing variants')
                    ->description('Define pack-wise manufacturing variants for this product. BOMs and finished goods stock are tracked per variant.')
                    ->visible(fn (?Product $record): bool => $record !== null && (
                        (bool) $record->manufacturing_enabled
                        || $record->activeBom()->exists()
                    ))
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

    /**
     * @return array<string, string>
     */
    public static function gstOptions(): array
    {
        return [
            '0' => '0%',
            '5' => '5%',
            '12' => '12%',
            '18' => '18%',
            '28' => '28%',
        ];
    }

    public static function normalizeGstKey(mixed $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        return (string) (int) round((float) $state);
    }
}
