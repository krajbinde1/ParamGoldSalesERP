<?php

namespace App\Filament\Resources\Crops\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CropsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('sort_order')->sortable(),
                IconColumn::make('status')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
