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
                    ->label('Period / Month')
                    ->state(fn ($record): string => $record->week_start_date->format('F Y')),
                TextColumn::make('week_start_date')
                    ->label('From Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('week_end_date')
                    ->label('To Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('sales_target')
                    ->label('Sales Target')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('collection_target')
                    ->label('Collection Target')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('field_activity_target')
                    ->label('Field Activity Target')
                    ->sortable(),
                TextColumn::make('remark')
                    ->label('Remark')
                    ->limit(40)
                    ->toggleable(),
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
