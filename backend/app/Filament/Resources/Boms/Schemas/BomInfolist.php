<?php

namespace App\Filament\Resources\Boms\Schemas;

use App\Models\Bom;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class BomInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('notes')
                    ->hiddenLabel()
                    ->html()
                    ->state(fn (Bom $record): string => (string) ($record->notes ?? ''))
                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(
                        '<p class="pg-bom-notes"><strong>Notes</strong> '.e((string) $state).'</p>'
                    ))
                    ->visible(fn (Bom $record): bool => filled($record->notes))
                    ->columnSpanFull(),
                Section::make('BOM Items')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('items_table')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Bom $record): string => 'items')
                            ->formatStateUsing(fn ($state, Bom $record): HtmlString => new HtmlString(
                                view('filament.resources.boms.partials.bom-items-table', [
                                    'record' => $record,
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),
                Section::make('BOM Summary')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('bom_summary')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (Bom $record): string => 'summary')
                            ->formatStateUsing(fn ($state, Bom $record): HtmlString => new HtmlString(
                                view('filament.resources.boms.partials.bom-summary', [
                                    'record' => $record,
                                ])->render()
                            ))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
