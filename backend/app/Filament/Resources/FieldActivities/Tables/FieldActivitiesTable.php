<?php

namespace App\Filament\Resources\FieldActivities\Tables;

use App\Models\FieldActivity;
use App\Filament\Support\EmployeeSelect;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FieldActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('activity_date', 'desc')
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (FieldActivity $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('farmer_name')
                    ->label('Farmer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('farmer_mobile')
                    ->label('Mobile')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('district')
                    ->label('District')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('village')
                    ->label('Village')
                    ->searchable(),
                TextColumn::make('taluka')
                    ->label('Taluka')
                    ->searchable(),
                TextColumn::make('crop.name')
                    ->label('Crop')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('activity_date')
                    ->label('Activity Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('activity_time')
                    ->label('Activity Time')
                    ->time('h:i A'),
                TextColumn::make('location')
                    ->label('Location')
                    ->state(fn (FieldActivity $record): ?string => filled($record->latitude) && filled($record->longitude)
                        ? sprintf('%s, %s', $record->latitude, $record->longitude)
                        : null)
                    ->url(fn (FieldActivity $record): ?string => $record->mapsUrl())
                    ->openUrlInNewTab()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FieldActivity::statusLabel($state))
                    ->color('success'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
