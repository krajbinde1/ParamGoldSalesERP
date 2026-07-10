<?php

namespace App\Filament\Resources\Dealers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DealerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('dealer_code'),
                TextEntry::make('firm_name'),
                TextEntry::make('owner_name'),
                TextEntry::make('mobile'),
                TextEntry::make('alternate_mobile')
                    ->placeholder('-'),
                TextEntry::make('email')
                    ->label('Email address')
                    ->placeholder('-'),
                TextEntry::make('gst_no')
                    ->placeholder('-'),
                TextEntry::make('address')
                    ->columnSpanFull(),
                TextEntry::make('state'),
                TextEntry::make('district'),
                TextEntry::make('taluka'),
                TextEntry::make('village')
                    ->placeholder('-'),
                TextEntry::make('pincode'),
                TextEntry::make('credit_limit')
                    ->numeric(),
                TextEntry::make('outstanding')
                    ->numeric(),
                TextEntry::make('latitude')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('longitude')
                    ->numeric()
                    ->placeholder('-'),
                IconEntry::make('status')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
