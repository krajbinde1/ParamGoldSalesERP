<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Order Overview')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('order_no')
                            ->label('Order No')
                            ->formatStateUsing(fn (?string $state, Order $record): string => $record->shortOrderNo())
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('order_date')
                            ->label('Order Date')
                            ->date('d M Y'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => Order::statusColor($state)),

                        TextEntry::make('dealer.firm_name')
                            ->label('Dealer Name')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state, Order $record): string => filled($state)
                                ? $state
                                : ($record->dealer?->firm_name ?: '—'))
                            ->weight(FontWeight::SemiBold),
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
                        TextEntry::make('grand_total')
                            ->label(fn (Order $record): string => \App\Services\Orders\OrderBillingTransportCalculator::hasSavedAdjustment($record)
                                ? 'Final Grand Total'
                                : 'Grand Total')
                            ->money('INR')
                            ->weight(FontWeight::Bold),
                    ]),

                Section::make('Transport & Billing Total')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->visible(fn (Order $record): bool => filled($record->vehicle_number)
                        || filled($record->transport_charge_type)
                        || $record->transport_amount !== null
                        || $record->original_grand_total !== null)
                    ->schema([
                        TextEntry::make('vehicle_number')
                            ->label('Vehicle No')
                            ->placeholder('—'),
                        TextEntry::make('transport_charge_type')
                            ->label('Transport Charge Type')
                            ->placeholder('—')
                            ->formatStateUsing(function (?string $state): string {
                                return \App\Enums\TransportChargeType::tryFrom((string) $state)?->label() ?: '—';
                            }),
                        TextEntry::make('transport_amount')
                            ->label('Transport Charges')
                            ->money('INR')
                            ->placeholder('—'),
                        TextEntry::make('original_grand_total')
                            ->label('Original Order Total')
                            ->money('INR')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => $record->original_grand_total !== null),
                        TextEntry::make('transport_adjustment')
                            ->label('Adjustment')
                            ->formatStateUsing(function ($state, Order $record): string {
                                if ($record->transport_adjustment === null) {
                                    return '—';
                                }

                                return \App\Services\Orders\OrderBillingTransportCalculator::formatAdjustment(
                                    (float) $record->transport_adjustment,
                                );
                            })
                            ->visible(fn (Order $record): bool => $record->transport_adjustment !== null),
                        TextEntry::make('grand_total')
                            ->label('Final Grand Total')
                            ->money('INR')
                            ->weight(FontWeight::Bold)
                            ->visible(fn (Order $record): bool => \App\Services\Orders\OrderBillingTransportCalculator::hasSavedAdjustment($record)),
                    ]),

                Section::make('Order Items')
                    ->columnSpanFull()
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

                Grid::make([
                    'default' => 1,
                    'lg' => 5,
                ])
                    ->schema([
                        Section::make('Order Summary')
                            ->columnSpan([
                                'default' => 1,
                                'lg' => 2,
                            ])
                            ->schema([
                                TextEntry::make('order_summary')
                                    ->hiddenLabel()
                                    ->html()
                                    ->state(fn (Order $record): string => 'summary')
                                    ->formatStateUsing(fn ($state, Order $record): HtmlString => new HtmlString(
                                        view('filament.resources.orders.partials.order-summary', [
                                            'record' => $record,
                                        ])->render()
                                    ))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Order Workflow')
                            ->columnSpan([
                                'default' => 1,
                                'lg' => 3,
                            ])
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
                    ]),

                Section::make('Billing')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->bill_path)
                        || filled($record->bill_number)
                        || filled($record->bill_date))
                    ->schema([
                        TextEntry::make('bill_number')
                            ->label('Bill Number')
                            ->placeholder('—')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('bill_date')
                            ->label('Bill Date')
                            ->date('d M Y')
                            ->placeholder('—'),
                        TextEntry::make('bill_path')
                            ->label('Bill PDF')
                            ->formatStateUsing(fn (?string $state, Order $record): string => filled($record->billUrl())
                                ? 'View Bill / Download PDF'
                                : '—')
                            ->url(fn (?string $state, Order $record): ?string => $record->billUrl())
                            ->openUrlInNewTab()
                            ->color('primary')
                            ->weight(FontWeight::SemiBold)
                            ->placeholder('—'),
                    ]),

                Section::make('Remarks')
                    ->visible(fn (Order $record): bool => filled($record->remarks)
                        || filled($record->rejection_remark)
                        || filled($record->dispatch_remark)
                        || filled($record->billing_remark)
                        || filled($record->transport_remark))
                    ->schema([
                        TextEntry::make('remarks')
                            ->label('Order Remarks')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->remarks))
                            ->columnSpanFull(),
                        TextEntry::make('rejection_remark')
                            ->label('Rejection Reason')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->rejection_remark))
                            ->columnSpanFull(),
                        TextEntry::make('billing_remark')
                            ->label('Billing Remark')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->billing_remark))
                            ->columnSpanFull(),
                        TextEntry::make('transport_remark')
                            ->label('Transport Remark')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->transport_remark))
                            ->columnSpanFull(),
                        TextEntry::make('dispatch_remark')
                            ->label('Dispatch Remark')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => filled($record->dispatch_remark))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
