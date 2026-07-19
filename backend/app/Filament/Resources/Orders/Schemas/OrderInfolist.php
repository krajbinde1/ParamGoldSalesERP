<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order header')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('order_no')->label('Order No.'),
                        TextEntry::make('order_date')->date(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('dealer.firm_name')->label('Dealer'),
                        TextEntry::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-'),
                        TextEntry::make('payment_type')->badge(),
                        TextEntry::make('remarks')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Order items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->columns(6)
                            ->schema([
                                TextEntry::make('product.product_name')->label('Product'),
                                TextEntry::make('case_quantity')->label('Cases'),
                                TextEntry::make('nos_per_case')->label('Nos/Case'),
                                TextEntry::make('total_quantity_nos')->label('Total Nos'),
                                TextEntry::make('rate_per_no')->label('Rate/No')->money('INR'),
                                TextEntry::make('final_amount')->label('Final Amount')->money('INR'),
                            ]),
                    ]),
                Section::make('Totals')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')->label('Sub Total')->money('INR'),
                        TextEntry::make('discount_amount')->label('Discount')->money('INR'),
                        TextEntry::make('gst_amount')->label('GST')->money('INR'),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR'),
                    ]),
            ]);
    }
}
