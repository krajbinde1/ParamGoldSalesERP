<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order Summary')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        TextEntry::make('order_no')
                            ->label('Order No')
                            ->formatStateUsing(fn (?string $state, Order $record): string => $record->shortOrderNo())
                            ->weight(FontWeight::Bold)
                            ->copyable(false),
                        TextEntry::make('order_date')
                            ->label('Order Date')
                            ->date('d M Y'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => Order::statusColor($state)),
                        TextEntry::make('salesEmployee.full_name')
                            ->label('Sales Employee')
                            ->placeholder('—'),
                        TextEntry::make('approvedByUser.name')
                            ->label('Sales Manager')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state, Order $record): string {
                                if (filled($state)) {
                                    return $state;
                                }

                                $record->loadMissing('salesEmployee.reportingManager:id,full_name');

                                return $record->salesEmployee?->reportingManager?->full_name ?: '—';
                            }),
                        TextEntry::make('dealer.firm_name')
                            ->label('Dealer Name')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state, Order $record): string => filled($state)
                                ? $state
                                : ($record->dealer?->firm_name ?: '—')),
                        TextEntry::make('dealer.village')
                            ->label('Dealer Village')
                            ->placeholder('—'),
                        TextEntry::make('payment_type')
                            ->label('Payment Type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? ucfirst(strtolower($state))
                                : '—')
                            ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                                'cash' => 'success',
                                'credit' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('grand_total')
                            ->label('Grand Total')
                            ->money('INR')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                    ]),

                Section::make('Order Workflow')
                    ->schema([
                        TextEntry::make('billing_gate')
                            ->hiddenLabel()
                            ->visible(fn (Order $record): bool => $record->isAwaitingSendForBill())
                            ->state('Waiting for Production Supervisor to Send for Bill.')
                            ->color('warning')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('workflow_timeline')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Order $record): string => 'workflow')
                            ->formatStateUsing(fn ($state, Order $record): HtmlString => new HtmlString(
                                view('filament.resources.orders.partials.order-workflow-timeline', [
                                    'steps' => $record->workflowTimeline(),
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Dealer / Billing Party')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 4,
                    ])
                    ->schema([
                        TextEntry::make('dealer.firm_name')
                            ->label('Dealer Name')
                            ->placeholder('—')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('dealer.village')
                            ->label('Village')
                            ->placeholder('—'),
                        TextEntry::make('dealer.mobile')
                            ->label('Mobile')
                            ->placeholder('—'),
                        TextEntry::make('dealer.address')
                            ->label('Address')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->dealer?->address))
                            ->columnSpan([
                                'default' => 1,
                                'md' => 2,
                                'xl' => 4,
                            ]),
                    ]),

                Section::make('Transport')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->vehicle_number)
                        || filled($record->transport_amount)
                        || filled($record->transport_remark)
                        || in_array($record->status, [
                            Order::STATUS_PENDING_FOR_BILLING,
                            Order::STATUS_BILLED,
                            Order::STATUS_DISPATCHED,
                        ], true))
                    ->schema([
                        TextEntry::make('vehicle_number')->label('Vehicle Number')->placeholder('—'),
                        TextEntry::make('transport_amount')
                            ->label('Transport Freight')
                            ->money('INR')
                            ->placeholder('—'),
                        TextEntry::make('transport_remark')
                            ->label('Transport Remark')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Order Items')
                    ->schema([
                        TextEntry::make('items_table')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Order $record): string => 'items')
                            ->formatStateUsing(fn ($state, Order $record): HtmlString => new HtmlString(
                                view('filament.resources.orders.partials.order-items-table', [
                                    'record' => $record,
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Order Totals')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('INR'),
                        TextEntry::make('discount_amount')
                            ->label('Discount')
                            ->money('INR'),
                        TextEntry::make('taxable_value')
                            ->label('Taxable Value')
                            ->state(function (Order $record): float {
                                if ($record->taxable_amount_after_transport !== null) {
                                    return (float) $record->taxable_amount_after_transport;
                                }

                                return max(0, (float) $record->subtotal - (float) $record->discount_amount);
                            })
                            ->money('INR'),
                        TextEntry::make('cgst_amount')
                            ->label('CGST')
                            ->state(fn (Order $record): float => round(((float) $record->gst_amount) / 2, 2))
                            ->money('INR'),
                        TextEntry::make('sgst_amount')
                            ->label('SGST')
                            ->state(fn (Order $record): float => round(((float) $record->gst_amount) / 2, 2))
                            ->money('INR'),
                        TextEntry::make('grand_total')
                            ->label('Grand Total')
                            ->money('INR')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                    ]),

                Section::make('Bill')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->billed_at)
                        || filled($record->bill_path)
                        || filled($record->bill_number)
                        || in_array($record->status, [
                            Order::STATUS_BILLED,
                            Order::STATUS_DISPATCHED,
                        ], true))
                    ->schema([
                        TextEntry::make('bill_number')->label('Bill Number')->placeholder('—'),
                        TextEntry::make('bill_date')->label('Bill Date')->date('d M Y')->placeholder('—'),
                        TextEntry::make('bill_path')
                            ->label('Bill PDF')
                            ->formatStateUsing(fn (?string $state, Order $record): string => filled($record->billUrl())
                                ? 'View / Download Bill PDF'
                                : '—')
                            ->url(fn (?string $state, Order $record): ?string => $record->billUrl())
                            ->openUrlInNewTab()
                            ->color('primary')
                            ->weight(FontWeight::SemiBold)
                            ->placeholder('—'),
                    ]),

                Section::make('Rejection Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_REJECTED || filled($record->rejected_at))
                    ->schema([
                        TextEntry::make('rejected_by_role')->label('Rejected By Role')->placeholder('—'),
                        TextEntry::make('rejectedByUser.name')->label('Rejected By')->placeholder('—'),
                        TextEntry::make('rejected_at')->label('Rejected At')->dateTime()->placeholder('—'),
                        TextEntry::make('rejection_remark')->label('Remarks')->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make('Dispatch Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->dispatched_at))
                    ->schema([
                        TextEntry::make('dispatch_date')->label('Dispatch Date')->date()->placeholder('—'),
                        TextEntry::make('dispatched_at')->label('Dispatched At')->dateTime()->placeholder('—'),
                        TextEntry::make('dispatchedByUser.name')->label('Dispatched By')->placeholder('—'),
                        TextEntry::make('transport_type')->label('Transport Type')->placeholder('—'),
                        TextEntry::make('lr_number')->label('LR Number')->placeholder('—'),
                        TextEntry::make('transporter_name')->label('Transport Name')->placeholder('—'),
                        TextEntry::make('dispatch_remark')->label('Dispatch Remark')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
