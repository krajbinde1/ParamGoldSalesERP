<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Models\PaymentRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class PaymentRequestInfolist
{
    /**
     * Page-only status badge colors (presentation).
     * Pending → amber, Approved for Payment → blue, Payment Done → green, Rejected → red.
     */
    private static function detailStatusColor(string $status): string
    {
        return match ($status) {
            PaymentRequest::STATUS_PENDING_FIRST, PaymentRequest::STATUS_PENDING_SECOND => 'warning',
            PaymentRequest::STATUS_APPROVED_FOR_PAYMENT => 'info',
            PaymentRequest::STATUS_PAYMENT_DONE => 'success',
            PaymentRequest::STATUS_REJECTED_FIRST, PaymentRequest::STATUS_REJECTED_SECOND => 'danger',
            default => 'gray',
        };
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])
                    ->schema([
                        Section::make('Request Overview')
                            ->columnSpan(1)
                            ->schema([
                                Grid::make([
                                    'default' => 1,
                                    'md' => 2,
                                    'xl' => 3,
                                ])
                                    ->schema([
                                        TextEntry::make('request_no')
                                            ->label('Request No')
                                            ->weight(FontWeight::Medium),
                                        TextEntry::make('created_at')
                                            ->label('Date')
                                            ->dateTime('d M Y'),
                                        TextEntry::make('status')
                                            ->label('Current Status')
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => PaymentRequest::statusLabel($state))
                                            ->color(fn (string $state): string => self::detailStatusColor($state)),
                                        TextEntry::make('vendor_name')
                                            ->label('Vendor Name'),
                                        TextEntry::make('vendor_mobile')
                                            ->label('Vendor Mobile')
                                            ->placeholder('—'),
                                        TextEntry::make('amount')
                                            ->label('Amount')
                                            ->money('INR')
                                            ->weight(FontWeight::Bold),
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
                                        TextEntry::make('edit_lock_status')
                                            ->label('Edit Access')
                                            ->state(function (PaymentRequest $record): HtmlString|string {
                                                if (! $record->isLockedForAdminEdits()) {
                                                    return 'Editable';
                                                }

                                                return new HtmlString(
                                                    '<span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">'
                                                    .'<span aria-hidden="true">🔒</span>'
                                                    .'Locked after Director Approval'
                                                    .'</span>'
                                                );
                                            })
                                            ->html()
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Section::make('Supporting Documents')
                            ->columnSpan(1)
                            ->schema([
                                TextEntry::make('supporting_documents_panel')
                                    ->hiddenLabel()
                                    ->html()
                                    ->state(fn (PaymentRequest $record): string => 'docs')
                                    ->formatStateUsing(function ($state, PaymentRequest $record): HtmlString {
                                        $docs = $record->supportingDocuments()->with('uploadedByUser:id,name')->get();

                                        return new HtmlString(
                                            view('filament.resources.payment-requests.partials.supporting-documents', [
                                                'documents' => $docs,
                                            ])->render()
                                        );
                                    })
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])
                    ->schema([
                        Section::make('Approval Timeline')
                            ->columnSpan(1)
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

                        Section::make('Payment Proof')
                            ->columnSpan(1)
                            ->visible(fn (PaymentRequest $record): bool => $record->status === PaymentRequest::STATUS_PAYMENT_DONE
                                || filled($record->payment_proof_path))
                            ->schema([
                                TextEntry::make('payment_done_at')
                                    ->label('Payment Done At')
                                    ->dateTime('d M Y • h:i A')
                                    ->placeholder('—'),
                                TextEntry::make('paymentDoneByUser.name')
                                    ->label('Payment Done By')
                                    ->placeholder('—'),
                                TextEntry::make('payment_remark')
                                    ->label('Payment Remark')
                                    ->placeholder('—'),
                                TextEntry::make('payment_proof_action')
                                    ->label('Payment Proof')
                                    ->state(function (PaymentRequest $record): HtmlString|string {
                                        $url = $record->paymentProofUrl();
                                        if (blank($url)) {
                                            return '—';
                                        }

                                        return new HtmlString(
                                            '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" '
                                            .'class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">'
                                            .'<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">'
                                            .'<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />'
                                            .'<path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />'
                                            .'</svg>'
                                            .'View Proof'
                                            .'</a>'
                                        );
                                    })
                                    ->html(),
                            ]),
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
            ]);
    }
}
