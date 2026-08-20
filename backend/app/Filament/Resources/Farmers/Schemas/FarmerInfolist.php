<?php

namespace App\Filament\Resources\Farmers\Schemas;

use App\Models\Farmer;
use App\Models\FieldActivity;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FarmerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Farmer')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->label('Farmer Name'),
                        TextEntry::make('mobile')->label('Mobile Number'),
                        TextEntry::make('district.name')->label('District')->placeholder('—'),
                        TextEntry::make('taluka.name')->label('Taluka')->placeholder('—'),
                        TextEntry::make('village'),
                        TextEntry::make('createdByEmployee.full_name')
                            ->label('Created By')
                            ->formatStateUsing(fn ($state, Farmer $record): string => $record->createdByEmployee?->displayLabel() ?? '—'),
                        TextEntry::make('first_contact_date')->date('d M Y')->placeholder('—'),
                        TextEntry::make('last_activity_date')->date('d M Y')->placeholder('—'),
                        TextEntry::make('field_activities_count')
                            ->label('Total Activities')
                            ->state(fn (Farmer $record): int => $record->fieldActivities()->count()),
                    ]),
                RepeatableEntry::make('fieldActivities')
                    ->label('Activity History')
                    ->schema([
                        TextEntry::make('activity_date')->date('d M Y'),
                        TextEntry::make('employee.full_name')->label('Employee'),
                        TextEntry::make('crop.name')->label('Crop')->placeholder('—'),
                        TextEntry::make('recommendations_summary')
                            ->label('Recommended Products')
                            ->state(function (FieldActivity $record): string {
                                $record->loadMissing('recommendations.product');

                                return $record->recommendations
                                    ->map(function ($row): string {
                                        $name = $row->product?->product_name ?? 'Product';
                                        $extra = collect([$row->dosage, $row->remark])->filter()->implode(' • ');

                                        return $extra !== '' ? $name.' ('.$extra.')' : $name;
                                    })
                                    ->filter()
                                    ->implode(', ') ?: '—';
                            })
                            ->columnSpanFull(),
                        TextEntry::make('village'),
                        TextEntry::make('taluka'),
                        TextEntry::make('district')->placeholder('—'),
                        TextEntry::make('remark')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('location_map')
                            ->label('Location')
                            ->state(fn (FieldActivity $record): ?string => $record->mapsUrl() ? sprintf('%s, %s', $record->latitude, $record->longitude) : null)
                            ->url(fn (FieldActivity $record): ?string => $record->mapsUrl())
                            ->openUrlInNewTab()
                            ->placeholder('—'),
                        ImageEntry::make('photo_path')
                            ->label('Photo')
                            ->getStateUsing(fn (FieldActivity $record): ?string => $record->photoUrl())
                            ->url(fn (FieldActivity $record): ?string => $record->photoUrl())
                            ->openUrlInNewTab()
                            ->imageHeight(160)
                            ->visible(fn (FieldActivity $record): bool => filled($record->photo_path)),
                    ])
                    ->columns(3),
            ]);
    }
}
