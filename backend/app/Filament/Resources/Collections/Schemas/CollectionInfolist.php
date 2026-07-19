<?php

namespace App\Filament\Resources\Collections\Schemas;

use App\Models\Collection;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CollectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Collection details')->columns(3)->schema([
                    TextEntry::make('receipt_no')->label('Receipt No.'),
                    TextEntry::make('collection_date')->date(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('dealer.firm_name')->label('Dealer'),
                    TextEntry::make('salesEmployee.full_name')->label('Sales Employee')->placeholder('-'),
                    TextEntry::make('amount')->money('INR'),
                    TextEntry::make('remarks')->label('Employee Remarks')->placeholder('-')->columnSpanFull(),
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
            ]);
    }
}
