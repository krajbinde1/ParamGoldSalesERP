<?php

namespace App\Filament\Resources\CompanyTransportLedgers\Schemas;

use App\Models\CompanyTransportLedgerEntry;
use App\Services\Orders\CompanyTransportLedgerService;
use App\Support\IndianCurrency;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CompanyTransportLedgerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ledger Entry')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('transaction_date')->label('Date')->date('d M Y'),
                        TextEntry::make('entry_kind')
                            ->label('Type')
                            ->badge()
                            ->formatStateUsing(fn ($state, CompanyTransportLedgerEntry $record): string => $record->entry_kind?->label() ?? (string) $state)
                            ->color(fn (CompanyTransportLedgerEntry $record): string => $record->entry_kind?->color() ?? 'gray'),
                        TextEntry::make('particulars')->label('Particulars')->columnSpan(2),
                        TextEntry::make('order_no')
                            ->label('Order No.')
                            ->placeholder('—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => ! $record->isExpense()),
                        TextEntry::make('related_orders')
                            ->label('Related Orders')
                            ->state(function (CompanyTransportLedgerEntry $record): array {
                                $record->loadMissing('relatedOrders.dealer:id,firm_name');
                                $ledger = app(CompanyTransportLedgerService::class);

                                return $record->relatedOrders
                                    ->map(fn ($order): string => $ledger->presentRelatedOrder($order)['label'])
                                    ->values()
                                    ->all();
                            })
                            ->listWithLineBreaks()
                            ->placeholder('—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => $record->isExpense())
                            ->columnSpanFull(),
                        TextEntry::make('transport_charge_type')
                            ->label('Transport Type')
                            ->formatStateUsing(fn ($state, CompanyTransportLedgerEntry $record): string => $record->transportTypeLabel() ?: '—')
                            ->placeholder('—'),
                        TextEntry::make('vehicle_number')->label('Vehicle No.')->placeholder('—'),
                        TextEntry::make('debit_amount')
                            ->label('Debit')
                            ->formatStateUsing(fn ($state): string => (float) $state > 0 ? IndianCurrency::formatExact((float) $state) : '—'),
                        TextEntry::make('credit_amount')
                            ->label('Credit')
                            ->formatStateUsing(fn ($state): string => (float) $state > 0 ? IndianCurrency::formatExact((float) $state) : '—'),
                        TextEntry::make('expense_type')
                            ->label('Expense Type')
                            ->formatStateUsing(fn ($state, CompanyTransportLedgerEntry $record): string => $record->expenseTypeLabel() ?: '—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => $record->isExpense()),
                        TextEntry::make('expense_other_description')
                            ->label('Specify Other Expense')
                            ->placeholder('—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => $record->isExpense()
                                && filled($record->expense_other_description)),
                        TextEntry::make('paid_to')
                            ->label('Paid To')
                            ->placeholder('—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => $record->isExpense()),
                        TextEntry::make('payment_mode')
                            ->label('Payment Mode')
                            ->placeholder('—')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => $record->isExpense()),
                        TextEntry::make('remark')->label('Remark')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('enteredBy.name')->label('Entered By')->placeholder('—'),
                        TextEntry::make('entered_by_role')->label('Role')->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Entered At')
                            ->dateTime('d M Y h:i A', 'Asia/Kolkata'),
                        TextEntry::make('updatedBy.name')->label('Last Updated By')->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->label('Updated At')
                            ->dateTime('d M Y h:i A', 'Asia/Kolkata'),
                        ImageEntry::make('attachment_path')
                            ->label('Attachment')
                            ->disk('public')
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => filled($record->attachment_path)
                                && ! str_ends_with(strtolower((string) $record->attachment_path), '.pdf'))
                            ->columnSpanFull(),
                        TextEntry::make('attachment_link')
                            ->label('Attachment')
                            ->state(fn (CompanyTransportLedgerEntry $record): ?string => $record->attachmentUrl())
                            ->url(fn (CompanyTransportLedgerEntry $record): ?string => $record->attachmentUrl())
                            ->openUrlInNewTab()
                            ->visible(fn (CompanyTransportLedgerEntry $record): bool => filled($record->attachment_path))
                            ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Open attachment' : '—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Audit History')
                    ->collapsed()
                    ->schema([
                        RepeatableEntry::make('audits')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('action')->badge(),
                                TextEntry::make('actor.name')->label('By')->placeholder('—'),
                                TextEntry::make('actor_role')->label('Role')->placeholder('—'),
                                TextEntry::make('acted_at')->label('When')->dateTime('d M Y h:i A', 'Asia/Kolkata'),
                                TextEntry::make('old_values')
                                    ->label('Previous')
                                    ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<pre class="text-xs whitespace-pre-wrap">'.e(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—').'</pre>')),
                                TextEntry::make('new_values')
                                    ->label('New')
                                    ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<pre class="text-xs whitespace-pre-wrap">'.e(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '—').'</pre>')),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
