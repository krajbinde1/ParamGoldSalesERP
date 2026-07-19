<?php

namespace App\Filament\Resources\Targets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WeeklyTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('week_period')
                    ->label('Week Period')
                    ->state(fn ($record): string => $record->week_start_date->format('d M Y').' - '.$record->week_end_date->format('d M Y')),
                TextColumn::make('sales_target')
                    ->label('Sales Target')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('collection_target')
                    ->label('Collection Target')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
