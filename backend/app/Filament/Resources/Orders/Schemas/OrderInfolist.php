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
                Section::make('Order')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('order_no')
                            ->label('Order No')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('order_date')
                            ->label('Date')
                            ->date('d/m/Y'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, Order $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => Order::statusColor($state)),
                        TextEntry::make('salesEmployee.full_name')
                            ->label('Sales Employee')
                            ->placeholder('-'),
                        TextEntry::make('approvedByUser.name')
                            ->label('Sales Manager')
                            ->placeholder('-')
                            ->formatStateUsing(function (?string $state, Order $record): string {
                                if (filled($state)) {
                                    return $state;
                                }

                                $record->loadMissing('salesEmployee.reportingManager:id,full_name');

                                return $record->salesEmployee?->reportingManager?->full_name ?: '-';
                            }),
                    ]),

                Section::make('Order Workflow')
                    ->columns(1)
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
                            ->formatStateUsing(function (Order $record): HtmlString {
                                $html = collect($record->workflowTimeline())->map(function (array $step): string {
                                    $done = ! empty($step['completed']);
                                    $mark = $done ? '✓' : '○';
                                    $label = e((string) ($step['label'] ?? ''));
                                    $actor = e((string) ($step['actor'] ?? ''));
                                    $role = e((string) ($step['actor_role'] ?? ''));
                                    $at = e((string) ($step['at'] ?? ''));
                                    $statusText = e((string) ($step['status_text'] ?? ''));
                                    $lines = ["<div style=\"margin-bottom:10px\"><strong>{$mark} {$label}</strong>"];
                                    if ($actor !== '') {
                                        $roleBit = $role !== '' ? " • {$role}" : '';
                                        $lines[] = "<div>{$actor}{$roleBit}</div>";
                                    }
                                    if ($at !== '') {
                                        $lines[] = "<div style=\"color:#64748b\">{$at}</div>";
                                    }
                                    if ($statusText !== '') {
                                        $lines[] = "<div style=\"color:#64748b\">{$statusText}</div>";
                                    } elseif (! $done) {
                                        $lines[] = '<div style="color:#64748b">Pending</div>';
                                    }
                                    $lines[] = '</div>';

                                    return implode('', $lines);
                                })->implode('');

                                return new HtmlString($html);
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Dealer / Billing Party')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('dealer.dealer_code')->label('Dealer Code')->placeholder('-'),
                        TextEntry::make('dealer.firm_name')->label('Firm')->placeholder('-')->weight(FontWeight::SemiBold),
                        TextEntry::make('dealer.owner_name')->label('Owner')->placeholder('-'),
                        TextEntry::make('dealer.mobile')->label('Mobile')->placeholder('-'),
                        TextEntry::make('dealer.gst_no')->label('GSTIN')->placeholder('-'),
                        TextEntry::make('dealer.address')->label('Address')->placeholder('-')->columnSpan(3),
                        TextEntry::make('dealer.village')->label('Village')->placeholder('-'),
                        TextEntry::make('dealer.taluka')->label('Taluka')->placeholder('-'),
                        TextEntry::make('dealer.district')->label('District')->placeholder('-'),
                        TextEntry::make('dealer.state')->label('State')->placeholder('-'),
                        TextEntry::make('dealer.pincode')->label('Pincode')->placeholder('-'),
                    ]),

                Section::make('Payment')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('payment_type')
                            ->label('Payment Type')
                            ->badge()
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                                'cash' => 'success',
                                'credit' => 'warning',
                                default => 'gray',
                            }),
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
                        TextEntry::make('vehicle_number')->label('Vehicle Number')->placeholder('-'),
                        TextEntry::make('transport_amount')
                            ->label('Transport Freight')
                            ->money('INR')
                            ->alignEnd()
                            ->placeholder('-'),
                        TextEntry::make('transport_remark')
                            ->label('Transport Remark')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),

                Section::make('Products')
                    ->schema([
                        TextEntry::make('id')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->html()
                            ->formatStateUsing(function (Order $record): HtmlString {
                                $record->loadMissing('items.product:id,product_name');
                                $money = static fn ($value): string => '₹'.number_format((float) $value, 2);

                                $rows = $record->items->values()->map(function ($item, int $index) use ($money): string {
                                    $cases = (int) ($item->case_quantity ?? 0);
                                    $nos = (int) ($item->nos_per_case ?? 0);
                                    $total = (int) ($item->total_quantity_nos ?? ($cases * $nos));
                                    $qty = ($cases > 0 && $nos > 0)
                                        ? e("{$cases}×{$nos}={$total}")
                                        : e((string) ($item->quantity ?? $total ?: '-'));
                                    $name = e($item->product?->product_name ?: '-');

                                    return '<tr>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">'.($index + 1).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">'.$name.'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$qty.'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$money($item->rate).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$money($item->discount_amount).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$money($item->taxable_amount).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.e(rtrim(rtrim(number_format((float) $item->gst_percentage, 2), '0'), '.')).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;">'.$money($item->gst_amount).'</td>'
                                        .'<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:600;">'.$money($item->final_amount).'</td>'
                                        .'</tr>';
                                })->implode('');

                                if ($rows === '') {
                                    $rows = '<tr><td colspan="9" style="padding:12px;text-align:center;color:#6b7280;">No products</td></tr>';
                                }

                                $header = '<thead><tr style="background:#f8fafc;">'
                                    .'<th style="padding:8px;text-align:center;border-bottom:2px solid #cbd5e1;">Sr</th>'
                                    .'<th style="padding:8px;text-align:left;border-bottom:2px solid #cbd5e1;">Product</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">Qty</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">Rate</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">Discount</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">Taxable</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">GST%</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">GST Amt</th>'
                                    .'<th style="padding:8px;text-align:right;border-bottom:2px solid #cbd5e1;">Final</th>'
                                    .'</tr></thead>';

                                return new HtmlString(
                                    '<div style="overflow-x:auto;width:100%;">'
                                    .'<table style="width:100%;border-collapse:collapse;font-size:13px;">'
                                    .$header
                                    .'<tbody>'.$rows.'</tbody>'
                                    .'</table></div>'
                                );
                            }),
                    ]),

                Section::make('Summary')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Gross Product Value')
                            ->money('INR')
                            ->alignEnd(),
                        TextEntry::make('discount_amount')
                            ->label('(-) Discount')
                            ->money('INR')
                            ->alignEnd(),
                        TextEntry::make('taxable_amount_after_transport')
                            ->label('Taxable')
                            ->formatStateUsing(function ($state, Order $record): string {
                                $value = $state !== null
                                    ? (float) $state
                                    : max(0, (float) $record->subtotal - (float) $record->discount_amount);

                                return '₹'.number_format($value, 2);
                            })
                            ->alignEnd(),
                        TextEntry::make('gst_amount')
                            ->label('GST')
                            ->money('INR')
                            ->alignEnd(),
                        TextEntry::make('transport_amount')
                            ->label('Transport Freight')
                            ->money('INR')
                            ->alignEnd()
                            ->placeholder('0.00'),
                        TextEntry::make('grand_total')
                            ->label('Grand Total')
                            ->money('INR')
                            ->alignEnd()
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                    ]),

                Section::make('Billing Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->billed_at) || filled($record->bill_path))
                    ->schema([
                        TextEntry::make('bill_number')->label('Bill Number')->placeholder('-'),
                        TextEntry::make('bill_date')->label('Bill Date')->date('d/m/Y')->placeholder('-'),
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

                Section::make('Rejection Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => $record->status === Order::STATUS_REJECTED || filled($record->rejected_at))
                    ->schema([
                        TextEntry::make('rejected_by_role')->label('Rejected By Role')->placeholder('-'),
                        TextEntry::make('rejectedByUser.name')->label('Rejected By')->placeholder('-'),
                        TextEntry::make('rejected_at')->label('Rejected At')->dateTime()->placeholder('-'),
                        TextEntry::make('rejection_remark')->label('Remarks')->placeholder('-')->columnSpanFull(),
                    ]),

                Section::make('Dispatch Details')
                    ->columns(3)
                    ->visible(fn (Order $record): bool => filled($record->dispatched_at))
                    ->schema([
                        TextEntry::make('dispatch_date')->label('Dispatch Date')->date()->placeholder('-'),
                        TextEntry::make('dispatched_at')->label('Dispatched At')->dateTime()->placeholder('-'),
                        TextEntry::make('dispatchedByUser.name')->label('Dispatched By')->placeholder('-'),
                        TextEntry::make('transport_type')->label('Transport Type')->placeholder('-'),
                        TextEntry::make('lr_number')->label('LR Number')->placeholder('-'),
                        TextEntry::make('transporter_name')->label('Transport Name')->placeholder('-'),
                        TextEntry::make('dispatch_remark')->label('Dispatch Remark')->placeholder('-')->columnSpanFull(),
                    ]),

                Section::make('Status Timeline')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('')
                            ->formatStateUsing(function (Order $record): string {
                                return collect($record->workflowTimeline())
                                    ->map(function (array $step): string {
                                        $mark = ! empty($step['completed']) ? '✓' : '○';
                                        $lines = ["{$mark} **".$step['label'].'**'];
                                        if (filled($step['actor'] ?? null)) {
                                            $role = filled($step['actor_role'] ?? null)
                                                ? ' • '.$step['actor_role']
                                                : '';
                                            $lines[] = (string) $step['actor'].$role;
                                        }
                                        if (filled($step['at'] ?? null)) {
                                            $lines[] = (string) $step['at'];
                                        }
                                        if (filled($step['status_text'] ?? null)) {
                                            $lines[] = (string) $step['status_text'];
                                        } elseif (empty($step['completed'])) {
                                            $lines[] = 'Pending';
                                        }
                                        if (filled($step['remark'] ?? null)) {
                                            $lines[] = 'Remarks: '.$step['remark'];
                                        }

                                        return implode("\n", $lines);
                                    })
                                    ->implode("\n\n");
                            })
                            ->markdown()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
