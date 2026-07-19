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
                    TextEntry::make('week_start_date')->label('Week Start Date')->date(),
                    TextEntry::make('week_end_date')->label('Week End Date')->date(),
                    TextEntry::make('sales_target')->label('Weekly Sales Target')->money('INR'),
                    TextEntry::make('collection_target')->label('Weekly Collection Target')->money('INR'),
                ]),
            ]);
    }
}
