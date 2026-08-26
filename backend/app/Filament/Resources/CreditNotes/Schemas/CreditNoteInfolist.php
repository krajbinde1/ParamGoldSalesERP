<?php

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Models\CreditNote;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Credit Note Overview')
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])
                    ->schema([
                        TextEntry::make('credit_note_no')
                            ->label('Credit Note No')
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('type')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state, CreditNote $record): string => $record->typeLabel()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state, CreditNote $record): string => $record->displayStatusLabel())
                            ->color(fn (string $state): string => CreditNote::statusColor($state)),
                        TextEntry::make('credit_note_date')
                            ->label('Credit Note Date')
                            ->date('d M Y'),
                        TextEntry::make('bill_reference')->label('Invoice / Bill Reference'),
                        TextEntry::make('amount')->money('INR')->weight(FontWeight::SemiBold),
                        TextEntry::make('dealer.firm_name')->label('Dealer'),
                        TextEntry::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-'),
                        TextEntry::make('created_at')->label('Created At')->dateTime('d M Y h:i A')->timezone('Asia/Kolkata'),
                        TextEntry::make('remarks')
                            ->label('Remarks')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        ImageEntry::make('supporting_document_path')
                            ->label('Supporting Document')
                            ->disk('public')
                            ->visibility('public')
                            ->getStateUsing(fn (CreditNote $record): ?string => $record->documentUrl())
                            ->url(fn (CreditNote $record): ?string => $record->documentUrl())
                            ->openUrlInNewTab()
                            ->imageHeight(240)
                            ->visible(fn (CreditNote $record): bool => filled($record->supporting_document_path) && $record->documentIsImage())
                            ->columnSpanFull(),
                        TextEntry::make('supporting_document_path')
                            ->label('Supporting Document')
                            ->formatStateUsing(fn (?string $state, CreditNote $record): string => filled($record->documentUrl()) ? 'View document' : '—')
                            ->url(fn (CreditNote $record): ?string => $record->documentUrl())
                            ->openUrlInNewTab()
                            ->visible(fn (CreditNote $record): bool => filled($record->supporting_document_path) && ! $record->documentIsImage())
                            ->columnSpanFull(),
                    ]),
                Section::make('Line Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('product.product_name')->label('Product'),
                                TextEntry::make('product.product_code')->label('Code'),
                                TextEntry::make('quantity')->numeric(3),
                                TextEntry::make('rate')
                                    ->label('Rate')
                                    ->money('INR')
                                    ->placeholder('—')
                                    ->visible(fn ($record): bool => filled($record?->rate)),
                                TextEntry::make('original_rate')
                                    ->label('Original Rate')
                                    ->money('INR')
                                    ->placeholder('—')
                                    ->visible(fn ($record): bool => filled($record?->original_rate)),
                                TextEntry::make('revised_rate')
                                    ->label('Revised Rate')
                                    ->money('INR')
                                    ->placeholder('—')
                                    ->visible(fn ($record): bool => filled($record?->revised_rate)),
                                TextEntry::make('amount')->money('INR'),
                                TextEntry::make('reason')->label('Reason')->placeholder('-')->columnSpanFull(),
                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 3,
                            ]),
                    ]),
                Section::make('Approval Timeline')
                    ->schema([
                        TextEntry::make('workflow_timeline')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (CreditNote $record): string => 'workflow')
                            ->formatStateUsing(fn ($state, CreditNote $record): HtmlString => new HtmlString(
                                view('filament.resources.credit-notes.partials.credit-note-workflow-timeline', [
                                    'steps' => $record->workflowTimeline(),
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
