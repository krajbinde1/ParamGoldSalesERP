<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Models\PaymentRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class PaymentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Overview')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('request_no')
                            ->label('Request No')
                            ->weight(FontWeight::Bold),
                        TextEntry::make('created_at')
                            ->label('Date')
                            ->dateTime('d M Y, h:i A'),
                        TextEntry::make('status')
                            ->label('Current Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => PaymentRequest::statusLabel($state))
                            ->color(fn (string $state): string => PaymentRequest::statusColor($state)),
                        TextEntry::make('vendor_name')->label('Vendor Name'),
                        TextEntry::make('vendor_mobile')->label('Vendor Mobile'),
                        TextEntry::make('amount')->label('Amount')->money('INR')->weight(FontWeight::Bold),
                        TextEntry::make('remark')
                            ->label('Remark')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('createdByUser.name')
                            ->label('Created By')
                            ->placeholder('—'),
                        TextEntry::make('payment_status')
                            ->label('Payment Status')
                            ->state(fn (PaymentRequest $record): string => $record->paymentStatusLabel()),
                        TextEntry::make('reminder_summary')
                            ->label('Reminders')
                            ->state(function (PaymentRequest $record): string {
                                $count = (int) $record->reminder_count;
                                if ($count <= 0) {
                                    return 'Not reminded yet';
                                }

                                $last = $record->last_reminded_at
                                    ? $record->last_reminded_at->timezone('Asia/Kolkata')->format('d M Y, g:i A')
                                    : '—';

                                return "Reminded {$count} time".($count === 1 ? '' : 's')."\nLast Reminder: {$last}";
                            })
                            ->visible(fn (PaymentRequest $record): bool => $record->isAwaitingApproval()
                                || (int) $record->reminder_count > 0)
                            ->columnSpanFull(),
                    ]),

                Section::make('Approval Timeline')
                    ->schema([
                        TextEntry::make('timeline')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (PaymentRequest $record): string => 'timeline')
                            ->formatStateUsing(fn ($state, PaymentRequest $record): HtmlString => new HtmlString(
                                view('filament.resources.payment-requests.partials.approval-timeline', [
                                    'steps' => $record->approvalTimeline(),
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Rejection Details')
                    ->columns(2)
                    ->visible(fn (PaymentRequest $record): bool => $record->isRejected())
                    ->schema([
                        TextEntry::make('rejected_by_display')
                            ->label('Rejected By')
                            ->state(function (PaymentRequest $record): string {
                                if ($record->status === PaymentRequest::STATUS_REJECTED_FIRST) {
                                    return trim(($record->first_approver_name ?: '—').' ('.($record->first_approver_role ?: 'First Approver').')');
                                }

                                return trim(($record->second_approver_name ?: '—').' ('.($record->second_approver_role ?: 'Second Approver').')');
                            }),
                        TextEntry::make('rejected_at_display')
                            ->label('Date & Time')
                            ->state(function (PaymentRequest $record): string {
                                $at = $record->status === PaymentRequest::STATUS_REJECTED_FIRST
                                    ? $record->first_approved_at
                                    : $record->second_approved_at;

                                return $at ? $at->timezone('Asia/Kolkata')->format('d M Y, h:i A') : '—';
                            }),
                        TextEntry::make('rejection_remark_display')
                            ->label('Rejection Remark')
                            ->state(fn (PaymentRequest $record): string => $record->status === PaymentRequest::STATUS_REJECTED_FIRST
                                ? ($record->first_rejection_remark ?: '—')
                                : ($record->second_rejection_remark ?: '—'))
                            ->columnSpanFull(),
                    ]),

                Section::make('Payment Proof')
                    ->columns(3)
                    ->visible(fn (PaymentRequest $record): bool => $record->status === PaymentRequest::STATUS_PAYMENT_DONE
                        || filled($record->payment_proof_path))
                    ->schema([
                        TextEntry::make('payment_done_at')
                            ->label('Payment Done At')
                            ->dateTime('d M Y, h:i A')
                            ->placeholder('—'),
                        TextEntry::make('paymentDoneByUser.name')
                            ->label('Payment Done By')
                            ->placeholder('—'),
                        TextEntry::make('payment_proof_path')
                            ->label('Payment Proof')
                            ->formatStateUsing(fn (?string $state, PaymentRequest $record): string => filled($record->paymentProofUrl())
                                ? 'View / Download Proof'
                                : '—')
                            ->url(fn (?string $state, PaymentRequest $record): ?string => $record->paymentProofUrl())
                            ->openUrlInNewTab()
                            ->color('primary')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('payment_remark')
                            ->label('Payment Remark')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
