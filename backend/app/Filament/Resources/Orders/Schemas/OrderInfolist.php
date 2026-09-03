<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Services\Orders\OrderBillingTransportCalculator;
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
                            ->label('Grand Total')
                            ->state(fn (Order $record): float => OrderBillingTransportCalculator::finalGrandTotal($record))
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
                                return TransportChargeType::tryFrom((string) $state)?->label() ?: '—';
                            }),
                        TextEntry::make('transport_amount')
                            ->label('Transport Charges')
                            ->formatStateUsing(function ($state, Order $record): string {
                                if ($record->transport_amount === null && $record->transport_adjustment === null) {
                                    return '—';
                                }

                                if ($record->transport_adjustment !== null) {
                                    return OrderBillingTransportCalculator::formatAdjustment(
                                        (float) $record->transport_adjustment,
                                    );
                                }

                                return OrderBillingTransportCalculator::formatMoney(
                                    (float) $record->transport_amount,
                                );
                            })
                            ->placeholder('—'),
                        TextEntry::make('gst_amount')
                            ->label('GST')
                            ->state(fn (Order $record): float => (float) OrderBillingTransportCalculator::present($record)['gst_amount'])
                            ->money('INR')
                            ->visible(fn (Order $record): bool => OrderBillingTransportCalculator::hasSavedAdjustment($record)),
                        TextEntry::make('round_off')
                            ->label('Round Off')
                            ->state(fn (Order $record): string => OrderBillingTransportCalculator::formatRoundOff(
                                (float) OrderBillingTransportCalculator::present($record)['round_off'],
                            )),
                        TextEntry::make('grand_total')
                            ->label('Grand Total')
                            ->state(fn (Order $record): float => OrderBillingTransportCalculator::finalGrandTotal($record))
                            ->money('INR')
                            ->weight(FontWeight::Bold)
                            ->visible(fn (Order $record): bool => OrderBillingTransportCalculator::hasSavedAdjustment($record)),
                    ]),

                Section::make('Transport Correction Audit')
                    ->columnSpanFull()
                    ->visible(fn (Order $record): bool => $record->usedEditPermissionAudits() !== [])
                    ->schema([
                        TextEntry::make('transport_correction_audit')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Order $record): string => 'audit')
                            ->formatStateUsing(fn ($state, Order $record): HtmlString => new HtmlString(
                                view('filament.resources.orders.partials.order-edit-audit', [
                                    'audits' => $record->usedEditPermissionAudits(),
                                ])->render()
                            ))
                            ->columnSpanFull(),
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
                                TextEntry::make('dispatched_edit_pending_banner')
                                    ->hiddenLabel()
                                    ->visible(fn (Order $record): bool => $record->hasPendingEditPermission())
                                    ->state(function (Order $record): string {
                                        $request = $record->openEditPermissionRequest();

                                        return 'Edit permission requested. Waiting for Director approval. This order remains locked.'
                                            .(filled($request?->reason) ? ' Reason: '.$request->reason : '');
                                    })
                                    ->color('warning')
                                    ->weight(FontWeight::SemiBold)
                                    ->columnSpanFull(),
                                TextEntry::make('dispatched_edit_approved_banner')
                                    ->hiddenLabel()
                                    ->visible(fn (Order $record): bool => $record->hasApprovedUnusedEditPermission())
                                    ->state('Director approved a one-time correction. You may edit Vehicle No., Transport Type, and Transport Charges. Saving will lock the order again.')
                                    ->color('info')
                                    ->weight(FontWeight::SemiBold)
                                    ->columnSpanFull(),
                                TextEntry::make('billing_gate')
                                    ->hiddenLabel()
                                    ->visible(fn (Order $record): bool => $record->isAwaitingSendForBill())
                                    ->state('Waiting for Production Supervisor to Send for Bill.')
                                    ->color('warning')
                                    ->weight(FontWeight::SemiBold),
                                TextEntry::make('on_hold_banner')
                                    ->hiddenLabel()
                                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_ON_HOLD)
                                    ->state(fn (Order $record): string => 'On Hold. Send for Bill is blocked until Release Hold.'
                                        .(filled($record->hold_remark) ? ' Remark: '.$record->hold_remark : ''))
                                    ->color('warning')
                                    ->weight(FontWeight::SemiBold)
                                    ->columnSpanFull(),
                                TextEntry::make('reverted_banner')
                                    ->hiddenLabel()
                                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_REVERTED_TO_MANAGER)
                                    ->state(fn (Order $record): string => 'Returned by Production. Waiting for Manager re-approval.'
                                        .(filled($record->revert_remark) ? ' Remark: '.$record->revert_remark : ''))
                                    ->color('info')
                                    ->weight(FontWeight::SemiBold)
                                    ->columnSpanFull(),
                                TextEntry::make('hold_details')
                                    ->label('Hold / Revert Details')
                                    ->visible(fn (Order $record): bool => filled($record->held_at) || filled($record->reverted_at))
                                    ->state(function (Order $record): string {
                                        $record->loadMissing(['heldByUser:id,name', 'revertedByUser:id,name', 'reapprovedByUser:id,name']);
                                        $lines = [];
                                        if (filled($record->held_at)) {
                                            $lines[] = 'Held by: '.($record->heldByUser?->name ?: '—');
                                            $lines[] = 'Held at: '.$record->held_at->timezone('Asia/Kolkata')->format('d M Y • h:i A');
                                            if (filled($record->hold_remark)) {
                                                $lines[] = 'Hold remark: '.$record->hold_remark;
                                            }
                                        }
                                        if (filled($record->reverted_at)) {
                                            $lines[] = 'Reverted by: '.($record->revertedByUser?->name ?: '—');
                                            $lines[] = 'Reverted at: '.$record->reverted_at->timezone('Asia/Kolkata')->format('d M Y • h:i A');
                                            if (filled($record->revert_remark)) {
                                                $lines[] = 'Revert remark: '.$record->revert_remark;
                                            }
                                        }
                                        if (filled($record->reapproved_at)) {
                                            $lines[] = 'Re-approved by: '.($record->reapprovedByUser?->name ?: '—');
                                            $lines[] = 'Re-approved at: '.$record->reapproved_at->timezone('Asia/Kolkata')->format('d M Y • h:i A');
                                        }

                                        return implode("\n", $lines);
                                    })
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e($state ?? ''))))
                                    ->columnSpanFull(),
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
                        || filled($record->rejected_at)
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
                        TextEntry::make('rejected_by_role')
                            ->label('Rejected By')
                            ->visible(fn (Order $record): bool => $record->status === Order::STATUS_REJECTED
                                || filled($record->rejected_at))
                            ->formatStateUsing(fn (?string $state, Order $record): string => $record->displayStatusLabel()),
                        TextEntry::make('rejected_at')
                            ->label('Rejected At')
                            ->dateTime('d M Y h:i A')
                            ->timezone('Asia/Kolkata')
                            ->visible(fn (Order $record): bool => filled($record->rejected_at)),
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
