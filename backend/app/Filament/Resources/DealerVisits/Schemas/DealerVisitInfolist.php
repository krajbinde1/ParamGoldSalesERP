<?php

namespace App\Filament\Resources\DealerVisits\Schemas;

use App\Models\DealerVisit;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DealerVisitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dealer visit details')->columns(3)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('dealer.firm_name')
                        ->label('Dealer')
                        ->state(fn (DealerVisit $record): string => $record->displayDealerName()),
                    TextEntry::make('is_prospective')
                        ->label('Visit Type')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => (bool) $state ? 'Prospective Dealer Visit' : 'Dealer Visit')
                        ->color(fn ($state): string => (bool) $state ? 'warning' : 'success'),
                    TextEntry::make('prospective_owner_name')
                        ->label('Owner Name')
                        ->state(fn (DealerVisit $record): ?string => $record->displayOwnerName())
                        ->visible(fn (DealerVisit $record): bool => filled($record->displayOwnerName())),
                    TextEntry::make('prospective_mobile')
                        ->label('Mobile Number')
                        ->visible(fn (DealerVisit $record): bool => $record->is_prospective && filled($record->prospective_mobile)),
                    TextEntry::make('village')
                        ->label('Village')
                        ->state(fn (DealerVisit $record): ?string => $record->displayVillage())
                        ->visible(fn (DealerVisit $record): bool => filled($record->displayVillage())),
                    TextEntry::make('taluka')
                        ->label('Taluka')
                        ->state(fn (DealerVisit $record): ?string => $record->displayTaluka())
                        ->visible(fn (DealerVisit $record): bool => filled($record->displayTaluka())),
                    TextEntry::make('district')
                        ->label('District')
                        ->state(fn (DealerVisit $record): ?string => $record->displayDistrict())
                        ->visible(fn (DealerVisit $record): bool => filled($record->displayDistrict())),
                    TextEntry::make('remarks')
                        ->label('Remarks')
                        ->visible(fn (DealerVisit $record): bool => filled($record->remarks))
                        ->columnSpanFull(),
                    TextEntry::make('visit_date')->label('Visit Date')->date('d M Y'),
                    TextEntry::make('visit_time')->label('Visit Time')->time('h:i A'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => DealerVisit::statusLabel($state)),
                    TextEntry::make('latitude')
                        ->label('Latitude')
                        ->numeric(decimalPlaces: 7),
                    TextEntry::make('longitude')
                        ->label('Longitude')
                        ->numeric(decimalPlaces: 7),
                    TextEntry::make('accuracy')
                        ->label('Accuracy (m)')
                        ->numeric(decimalPlaces: 2),
                    TextEntry::make('location_captured_at')
                        ->label('Captured At')
                        ->dateTime('d M Y h:i A')
                        ->timezone('Asia/Kolkata'),
                    TextEntry::make('location_map')
                        ->label('Open in Map')
                        ->state(fn (DealerVisit $record): string => sprintf('%s, %s', $record->latitude, $record->longitude))
                        ->url(fn (DealerVisit $record): ?string => $record->mapsUrl())
                        ->openUrlInNewTab()
                        ->columnSpanFull(),
                    ImageEntry::make('photo_path')
                        ->label('Photo')
                        ->getStateUsing(fn (DealerVisit $record): ?string => $record->photoUrl())
                        ->url(fn (DealerVisit $record): ?string => $record->photoUrl())
                        ->openUrlInNewTab()
                        ->imageHeight(240)
                        ->visible(fn (DealerVisit $record): bool => filled($record->photo_path))
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
