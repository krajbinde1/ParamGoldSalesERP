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
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => \App\Models\Order::statusLabels()[$state] ?? $state)
                            ->color(fn (string $state): string => \App\Models\Order::statusColor($state)),
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
                Section::make('Billing')
                    ->columns(3)
                    ->visible(fn ($record): bool => filled($record->billed_at) || filled($record->bill_path))
                    ->schema([
                        TextEntry::make('bill_number')->label('Bill Number')->placeholder('-'),
                        TextEntry::make('billed_at')->label('Billed At')->dateTime()->placeholder('-'),
                        TextEntry::make('billedByUser.name')->label('Billed By')->placeholder('-'),
                        TextEntry::make('bill_path')
                            ->label('Bill')
                            ->formatStateUsing(fn ($record): string => $record->billUrl() ? 'View Bill' : '-')
                            ->url(fn ($record): ?string => $record->billUrl())
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                        TextEntry::make('billing_remark')->label('Billing Remark')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Dispatch')
                    ->columns(3)
                    ->visible(fn ($record): bool => filled($record->dispatched_at))
                    ->schema([
                        TextEntry::make('dispatch_date')->label('Dispatch Date')->date()->placeholder('-'),
                        TextEntry::make('dispatched_at')->label('Dispatched At')->dateTime()->placeholder('-'),
                        TextEntry::make('dispatchedByUser.name')->label('Dispatched By')->placeholder('-'),
                        TextEntry::make('transport_type')->label('Transport Type')->placeholder('-'),
                        TextEntry::make('transport_amount')->label('Transport Amount')->money('INR')->placeholder('-'),
                        TextEntry::make('transporter_name')->label('Transport Name')->placeholder('-'),
                        TextEntry::make('vehicle_number')->label('Vehicle Number')->placeholder('-'),
                        TextEntry::make('lr_number')->label('LR Number')->placeholder('-'),
                        TextEntry::make('dispatch_remark')->label('Dispatch Remark')->placeholder('-')->columnSpanFull(),
                    ]),
            ]);
    }
}
