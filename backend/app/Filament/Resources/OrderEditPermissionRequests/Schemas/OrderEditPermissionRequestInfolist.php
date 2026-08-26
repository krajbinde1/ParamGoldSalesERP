<?php

namespace App\Filament\Resources\OrderEditPermissionRequests\Schemas;

use App\Enums\TransportChargeType;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Services\Orders\OrderBillingTransportCalculator;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class OrderEditPermissionRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Request')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('status')
                            ->label('Request Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, OrderEditPermissionRequest $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => OrderEditPermissionRequest::statusColor($state)),
                        TextEntry::make('requestedByUser.name')
                            ->label('Requested by Admin')
                            ->placeholder('—')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('created_at')
                            ->label('Requested At')
                            ->dateTime('d M Y • h:i A', 'Asia/Kolkata'),
                        TextEntry::make('reason')
                            ->label('Reason for Edit')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('reviewedByUser.name')
                            ->label('Director')
                            ->placeholder('—')
                            ->visible(fn (OrderEditPermissionRequest $record): bool => filled($record->reviewed_by)),
                        TextEntry::make('reviewed_at')
                            ->label('Decision At')
                            ->dateTime('d M Y • h:i A', 'Asia/Kolkata')
                            ->visible(fn (OrderEditPermissionRequest $record): bool => filled($record->reviewed_at)),
                        TextEntry::make('adminReviewedByUser.name')
                            ->label('Admin')
                            ->placeholder('—')
                            ->visible(fn (OrderEditPermissionRequest $record): bool => filled($record->admin_reviewed_by)),
                        TextEntry::make('admin_reviewed_at')
                            ->label('Admin Decision At')
                            ->dateTime('d M Y • h:i A', 'Asia/Kolkata')
                            ->visible(fn (OrderEditPermissionRequest $record): bool => filled($record->admin_reviewed_at)),
                        TextEntry::make('rejection_remark')
                            ->label('Rejection Remark')
                            ->placeholder('—')
                            ->visible(fn (OrderEditPermissionRequest $record): bool => $record->isRejected())
                            ->columnSpanFull(),
                    ]),

                Section::make('Order Details')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('order.order_no')
                            ->label('Order No')
                            ->formatStateUsing(fn (?string $state, OrderEditPermissionRequest $record): string => $record->order?->shortOrderNo() ?: '—')
                            ->url(fn (OrderEditPermissionRequest $record): ?string => $record->order
                                ? OrderResource::getUrl('view', ['record' => $record->order])
                                : null)
                            ->weight(FontWeight::SemiBold)
                            ->color('primary'),
                        TextEntry::make('order.status')
                            ->label('Order Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state, OrderEditPermissionRequest $record): string => $record->order?->displayStatusLabel() ?: '—')
                            ->color(fn (?string $state): string => Order::statusColor((string) ($state ?: ''))),
                        TextEntry::make('order.dealer.firm_name')
                            ->label('Dealer')
                            ->placeholder('—'),
                        TextEntry::make('order.vehicle_number')
                            ->label('Vehicle No.')
                            ->placeholder('—'),
                        TextEntry::make('order.transport_charge_type')
                            ->label('Transport Type')
                            ->formatStateUsing(fn (?string $state): string => TransportChargeType::tryFrom((string) $state)?->label() ?: '—'),
                        TextEntry::make('order.transport_amount')
                            ->label('Transport Charges')
                            ->formatStateUsing(function ($state, OrderEditPermissionRequest $record): string {
                                if ($record->order?->transport_amount === null) {
                                    return '—';
                                }

                                return OrderBillingTransportCalculator::formatMoney((float) $record->order->transport_amount);
                            }),
                        TextEntry::make('order.grand_total')
                            ->label('Grand Total')
                            ->state(fn (OrderEditPermissionRequest $record): float => $record->order === null
                                ? 0.0
                                : OrderBillingTransportCalculator::finalGrandTotal($record->order))
                            ->money('INR'),
                    ]),

                Section::make('Correction Audit')
                    ->visible(fn (OrderEditPermissionRequest $record): bool => $record->isUsed())
                    ->schema([
                        TextEntry::make('editedByUser.name')
                            ->label('Edited By')
                            ->placeholder('—'),
                        TextEntry::make('edited_at')
                            ->label('Edit Date & Time')
                            ->dateTime('d M Y • h:i A', 'Asia/Kolkata'),
                        TextEntry::make('audit_table')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (OrderEditPermissionRequest $record): string => 'audit')
                            ->formatStateUsing(fn ($state, OrderEditPermissionRequest $record): HtmlString => new HtmlString(
                                view('filament.resources.orders.partials.order-edit-audit', [
                                    'audits' => [$record],
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
