<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
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
                Section::make('Order Information')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('order_no')->label('Order Number'),
                        TextEntry::make('order_date')->label('Order Date')->date(),
                        TextEntry::make('created_at')->label('Created At')->dateTime(),
                        TextEntry::make('status')
                            ->label('Current Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => Order::statusColor($state)),
                        TextEntry::make('salesEmployee.full_name')->label('Created By Employee')->placeholder('-'),
                        TextEntry::make('approvedByUser.name')->label('Sales Manager')->placeholder('-'),
                        TextEntry::make('payment_type')->badge(),
                        TextEntry::make('remarks')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Dealer Details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('dealer.dealer_code')->label('Dealer Code')->placeholder('-'),
                        TextEntry::make('dealer.firm_name')->label('Firm Name')->placeholder('-'),
                        TextEntry::make('dealer.owner_name')->label('Owner Name')->placeholder('-'),
                        TextEntry::make('dealer.mobile')->label('Mobile Number')->placeholder('-'),
                        TextEntry::make('dealer.address')->label('Address')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('dealer.village')->label('Village')->placeholder('-'),
                        TextEntry::make('dealer.taluka')->label('Taluka')->placeholder('-'),
                        TextEntry::make('dealer.district')->label('District')->placeholder('-'),
                        TextEntry::make('dealer.state')->label('State')->placeholder('-'),
                    ]),
                Section::make('Approval Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->approved_at))
                    ->schema([
                        TextEntry::make('approvedByUser.name')->label('Approved By')->placeholder('-'),
                        TextEntry::make('approved_at')->label('Approved At')->dateTime()->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Approval Status')
                            ->formatStateUsing(fn (): string => 'Approved by Sales Manager'),
                    ]),
                Section::make('Rejection Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_REJECTED || filled($record->rejected_at))
                    ->schema([
                        TextEntry::make('rejected_by_role')->label('Rejected By Role')->placeholder('-'),
                        TextEntry::make('rejectedByUser.name')->label('Rejected By')->placeholder('-'),
                        TextEntry::make('rejected_at')->label('Rejected At')->dateTime()->placeholder('-'),
                        TextEntry::make('rejection_remark')->label('Remarks')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Product Details')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('product.product_name')->label('Product Name'),
                                TextEntry::make('quantity')->label('Quantity'),
                                TextEntry::make('unit')->label('Unit')->placeholder('-'),
                                TextEntry::make('rate')->label('Rate')->money('INR'),
                                TextEntry::make('discount_amount')->label('Discount')->money('INR'),
                                TextEntry::make('taxable_amount')->label('Taxable Amount')->money('INR'),
                                TextEntry::make('gst_percentage')->label('GST %'),
                                TextEntry::make('gst_amount')->label('GST Amount')->money('INR'),
                                TextEntry::make('final_amount')->label('Final Amount')->money('INR'),
                                TextEntry::make('case_quantity')->label('Cases'),
                                TextEntry::make('nos_per_case')->label('Nos/Case'),
                                TextEntry::make('total_quantity_nos')->label('Total Nos'),
                            ]),
                    ]),
                Section::make('Order Summary')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money('INR'),
                        TextEntry::make('discount_amount')->label('Discount')->money('INR'),
                        TextEntry::make('transport_amount')->label('Transport Charges')->money('INR')->placeholder('-'),
                        TextEntry::make('taxable_amount_after_transport')
                            ->label('Taxable Value')
                            ->money('INR')
                            ->placeholder('-'),
                        TextEntry::make('gst_amount')->label('GST')->money('INR'),
                        TextEntry::make('grand_total')->label('Grand Total')->money('INR'),
                    ]),
                Section::make('Billing Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->billed_at) || filled($record->bill_path))
                    ->schema([
                        TextEntry::make('bill_number')->label('Bill Number')->placeholder('-'),
                        TextEntry::make('billed_at')->label('Billed At')->dateTime()->placeholder('-'),
                        TextEntry::make('billedByUser.name')->label('Billed By')->placeholder('-'),
                        TextEntry::make('bill_path')
                            ->label('Bill')
                            ->formatStateUsing(fn (Order $record): string => $record->billUrl() ? 'View Bill' : '-')
                            ->url(fn (Order $record): ?string => $record->billUrl())
                            ->openUrlInNewTab()
                            ->placeholder('-'),
                        TextEntry::make('billing_remark')->label('Billing Remark')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('Dispatch Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->dispatched_at))
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
                Section::make('Status Timeline')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('')
                            ->formatStateUsing(function (Order $record): string {
                                return collect($record->workflowTimeline())
                                    ->map(function (array $step): string {
                                        $lines = ['**'.$step['label'].'**'];
                                        if (filled($step['actor'] ?? null)) {
                                            $lines[] = (string) $step['actor'];
                                        }
                                        if (filled($step['at'] ?? null)) {
                                            $lines[] = (string) $step['at'];
                                        }
                                        if (filled($step['remark'] ?? null)) {
                                            $lines[] = 'Remarks: '.$step['remark'];
                                        }

                                        return implode("\n", $lines);
                                    })
                                    ->implode("\n\n↓\n\n");
                            })
                            ->markdown()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
