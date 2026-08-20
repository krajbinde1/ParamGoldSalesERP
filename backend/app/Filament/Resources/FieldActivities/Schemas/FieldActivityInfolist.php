<?php

namespace App\Filament\Resources\FieldActivities\Schemas;

use App\Models\FieldActivity;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FieldActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Field Activity details')->columns(3)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('farmer_name')->label('Farmer Name'),
                    TextEntry::make('farmer_mobile')->label('Farmer Mobile')->placeholder('—'),
                    TextEntry::make('district')->label('District')->placeholder('—'),
                    TextEntry::make('village')->label('Village'),
                    TextEntry::make('taluka')->label('Taluka'),
                    TextEntry::make('crop.name')->label('Crop')->placeholder('—'),
                    TextEntry::make('remark')->label('Activity Remark')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('recommendations_summary')
                        ->label('Product Recommendations')
                        ->state(function (FieldActivity $record): string {
                            $record->loadMissing('recommendations.product');

                            return $record->recommendations
                                ->map(function ($row): string {
                                    $name = $row->product?->product_name ?? 'Product';
                                    $extra = collect([$row->dosage, $row->remark])->filter()->implode(' • ');

                                    return $extra !== '' ? $name.' ('.$extra.')' : $name;
                                })
                                ->filter()
                                ->implode("\n") ?: '—';
                        })
                        ->columnSpanFull(),
                    TextEntry::make('activity_date')->label('Activity Date')->date('d M Y'),
                    TextEntry::make('activity_time')->label('Activity Time')->time('h:i A'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => FieldActivity::statusLabel($state)),
                    TextEntry::make('latitude')
                        ->label('Latitude')
                        ->numeric(decimalPlaces: 7)
                        ->placeholder('-'),
                    TextEntry::make('longitude')
                        ->label('Longitude')
                        ->numeric(decimalPlaces: 7)
                        ->placeholder('-'),
                    TextEntry::make('location_map')
                        ->label('Location')
                        ->state(fn (FieldActivity $record): ?string => filled($record->latitude) && filled($record->longitude)
                            ? sprintf('%s, %s', $record->latitude, $record->longitude)
                            : null)
                        ->url(fn (FieldActivity $record): ?string => filled($record->latitude) && filled($record->longitude)
                            ? sprintf('https://www.google.com/maps?q=%s,%s', $record->latitude, $record->longitude)
                            : null)
                        ->openUrlInNewTab()
                        ->placeholder('-')
                        ->columnSpanFull(),
                    ImageEntry::make('photo_path')
                        ->label('Photo')
                        ->getStateUsing(fn (FieldActivity $record): ?string => $record->photoUrl())
                        ->url(fn (FieldActivity $record): ?string => $record->photoUrl())
                        ->openUrlInNewTab()
                        ->imageHeight(240)
                        ->visible(fn (FieldActivity $record): bool => filled($record->photo_path))
                        ->columnSpanFull(),
                ]),
            ]);
    }
}
