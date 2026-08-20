<?php

namespace App\Filament\Resources\Targets\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WeeklyTargetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Weekly target')->columns(2)->schema([
                    TextEntry::make('employee.full_name')->label('Employee'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('week_start_date')->label('From Date')->date(),
                    TextEntry::make('week_end_date')->label('To Date')->date(),
                    TextEntry::make('period_month')
                        ->label('Period / Month')
                        ->state(fn ($record): string => $record->week_start_date->format('F Y')),
                    TextEntry::make('sales_target')->label('Weekly Sales Target')->money('INR'),
                    TextEntry::make('collection_target')->label('Weekly Collection Target')->money('INR'),
                    TextEntry::make('field_activity_target')->label('Field Activity Target'),
                    TextEntry::make('remark')->label('Remark')->placeholder('—')->columnSpanFull(),
                ]),
            ]);
    }
}
