<?php

namespace App\Filament\Resources\Products\Schemas;

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
            ]);
    }
}
