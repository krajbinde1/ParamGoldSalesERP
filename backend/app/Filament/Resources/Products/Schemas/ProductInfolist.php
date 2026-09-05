<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Services\Inventory\FinishedProductOpeningStockCalculator;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('product_code')->label('Product Code'),
                        TextEntry::make('product_name'),
                        TextEntry::make('category'),
                        TextEntry::make('brand')->placeholder('-'),
                        TextEntry::make('hsn_code')->label('HSN Code')->placeholder('-'),
                        TextEntry::make('uom'),
                        TextEntry::make('nos_per_case')->label('Nos Per Case'),
                        TextEntry::make('gst_percentage')->label('GST')->suffix('%'),
                        IconEntry::make('status')->label('Active')->boolean(),
                    ]),
                Section::make('Pricing and inventory')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('mrp')->label('MRP')->money('INR'),
                        TextEntry::make('distributor_price')->money('INR'),
                        TextEntry::make('dealer_price')->money('INR'),
                        TextEntry::make('retail_price')->money('INR'),
                        TextEntry::make('minimum_stock')->numeric(),
                    ]),
                Section::make('Manufacturing')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('current_finished_stock_cases')
                            ->label('Current Finished Stock')
                            ->state(fn (Product $record): float => FinishedProductOpeningStockCalculator::casesFromQty(
                                (float) $record->current_finished_stock,
                                (int) ($record->nos_per_case ?: 0),
                            ))
                            ->numeric(3)
                            ->suffix(' Cases'),
                        TextEntry::make('minimum_finished_stock_cases')
                            ->label('Minimum Finished Stock')
                            ->state(fn (Product $record): float => FinishedProductOpeningStockCalculator::casesFromQty(
                                (float) $record->minimum_finished_stock,
                                (int) ($record->nos_per_case ?: 0),
                            ))
                            ->numeric(3)
                            ->suffix(' Cases')
                            ->placeholder('-'),
                        TextEntry::make('shelf_life_days')->label('Shelf Life (Days)')->placeholder('-'),
                        IconEntry::make('batch_tracking_enabled')->label('Batch Tracking')->boolean(),
                        TextEntry::make('weighted_average_cost')->label('Weighted Average Cost')->money('INR'),
                    ]),
            ]);
    }
}
