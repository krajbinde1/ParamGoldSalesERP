<?php

namespace App\Filament\Resources\DealerApplications\Schemas;

use App\Models\DealerApplication;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class DealerApplicationInfolist
{
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
                        Section::make('Dealer Information')
                            ->columnSpan(1)
                            ->schema([
                                Grid::make(2)->schema([
                                    TextEntry::make('firm_name')->label('Dealer / Firm Name')->weight(FontWeight::Medium),
                                    TextEntry::make('owner_name')->label('Owner Name'),
                                    TextEntry::make('mobile')->label('Mobile'),
                                    TextEntry::make('gst_no')->label('GST Number')->placeholder('—'),
                                    TextEntry::make('state'),
                                    TextEntry::make('district'),
                                    TextEntry::make('taluka'),
                                    TextEntry::make('village')->label('Village / Location'),
                                    TextEntry::make('address')->placeholder('—')->columnSpanFull(),
                                    TextEntry::make('employee.full_name')
                                        ->label('Employee')
                                        ->formatStateUsing(fn ($state, DealerApplication $record): string => $record->employee?->displayLabel() ?? '—'),
                                    TextEntry::make('status')
                                        ->badge()
                                        ->formatStateUsing(fn (string $state): string => DealerApplication::STATUS_LABELS[$state] ?? $state),
                                    TextEntry::make('dealer.dealer_code')
                                        ->label('Dealer Code')
                                        ->placeholder('Not generated yet'),
                                    TextEntry::make('party.party_name')
                                        ->label('Party')
                                        ->placeholder('Not created yet'),
                                    TextEntry::make('duplicate_warning')
                                        ->label('Duplicate Review')
                                        ->formatStateUsing(fn (bool $state): string => $state ? 'Possible duplicate — review before approval' : 'No duplicate warning')
                                        ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                                        ->columnSpanFull(),
                                    TextEntry::make('coordinates')
                                        ->label('GPS')
                                        ->state(function (DealerApplication $record): string {
                                            if ($record->latitude === null || $record->longitude === null) {
                                                return 'Not captured';
                                            }

                                            return $record->latitude.', '.$record->longitude;
                                        })
                                        ->url(function (DealerApplication $record): ?string {
                                            if ($record->latitude === null || $record->longitude === null) {
                                                return null;
                                            }

                                            return 'https://www.google.com/maps?q='.$record->latitude.','.$record->longitude;
                                        }, shouldOpenInNewTab: true)
                                        ->columnSpanFull(),
                                ]),
                            ]),
                        Section::make('Documents')
                            ->columnSpan(1)
                            ->schema([
                                TextEntry::make('documents_panel')
                                    ->hiddenLabel()
                                    ->html()
                                    ->state(fn (): string => 'docs')
                                    ->formatStateUsing(function ($state, DealerApplication $record): HtmlString {
                                        $record->loadMissing('documents.uploadedByUser');

                                        return new HtmlString(
                                            view('filament.resources.dealer-applications.partials.documents', [
                                                'application' => $record,
                                                'slots' => $record->documentSlots(),
                                            ])->render()
                                        );
                                    })
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Section::make('Approval Timeline')
                    ->schema([
                        TextEntry::make('timeline_panel')
                            ->hiddenLabel()
                            ->html()
                            ->state(fn (): string => 'timeline')
                            ->formatStateUsing(function ($state, DealerApplication $record): HtmlString {
                                $record->loadMissing('events');

                                return new HtmlString(
                                    view('filament.resources.dealer-applications.partials.timeline', [
                                        'events' => $record->events,
                                        'application' => $record,
                                    ])->render()
                                );
                            }),
                    ]),
            ]);
    }
}
