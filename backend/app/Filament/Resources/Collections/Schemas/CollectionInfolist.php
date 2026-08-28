<?php

namespace App\Filament\Resources\Collections\Schemas;

use App\Models\Collection;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class CollectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Collection details')->columns(3)->schema([
                    TextEntry::make('receipt_no')->label('Receipt No.')->placeholder('—'),
                    TextEntry::make('collection_date')->date(),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => Collection::STATUS_LABELS[$state ?? ''] ?? (string) $state)
                        ->color(fn (?string $state): string => Collection::statusColor((string) $state)),
                    TextEntry::make('dealer.firm_name')->label('Dealer'),
                    TextEntry::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-'),
                    TextEntry::make('amount')->money('INR'),
                    TextEntry::make('payment_mode')->label('Payment Mode')->placeholder('—'),
                    TextEntry::make('bank_name')->label('Bank Name')->placeholder('—'),
                    TextEntry::make('transaction_number')->label('Transaction / Reference No.')->placeholder('—'),
                    TextEntry::make('remarks')->label('Remark')->placeholder('-')->columnSpanFull(),
                    ImageEntry::make('photo_path')
                        ->label('Photo')
                        ->disk('public')
                        ->visibility('public')
                        ->getStateUsing(fn (Collection $record): ?string => $record->photoUrl())
                        ->url(fn (Collection $record): ?string => $record->photoUrl())
                        ->openUrlInNewTab()
                        ->imageHeight(240)
                        ->visible(fn (Collection $record): bool => filled($record->photo_path))
                        ->columnSpanFull(),
                    TextEntry::make('admin_remark')
                        ->label('Admin Remark')
                        ->placeholder('-')
                        ->visible(fn ($record): bool => filled($record->admin_remark))
                        ->columnSpanFull(),
                ]),
                Section::make('Edit history')
                    ->columnSpanFull()
                    ->visible(fn (Collection $record): bool => $record->audits()->exists())
                    ->schema([
                        TextEntry::make('collection_edit_audit')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Collection $record): string => 'audit')
                            ->formatStateUsing(fn ($state, Collection $record): HtmlString => new HtmlString(
                                view('filament.resources.collections.partials.collection-edit-audit', [
                                    'audits' => $record->audits()->with('changedByUser')->get(),
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
