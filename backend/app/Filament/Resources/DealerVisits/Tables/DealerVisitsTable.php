<?php

namespace App\Filament\Resources\DealerVisits\Tables;

use App\Models\DealerVisit;
use App\Filament\Support\EmployeeSelect;
use App\Filament\Support\TodayDateFilter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DealerVisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('visit_date', 'desc')
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (DealerVisit $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable(['full_name', 'employee_code'])
                    ->sortable(),
                TextColumn::make('dealer.firm_name')
                    ->label('Dealer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('visit_date')
                    ->label('Visit Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('visit_time')
                    ->label('Visit Time')
                    ->time('h:i A'),
                TextColumn::make('location')
                    ->label('Location')
                    ->state(fn (DealerVisit $record): string => sprintf('%s, %s', $record->latitude, $record->longitude))
                    ->url(fn (DealerVisit $record): ?string => $record->mapsUrl())
                    ->openUrlInNewTab(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DealerVisit::statusLabel($state))
                    ->color('success'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                SelectFilter::make('dealer_id')
                    ->label('Dealer')
                    ->relationship('dealer', 'firm_name')
                    ->searchable()
                    ->preload(),
                TodayDateFilter::make('visit_date', 'Visit Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
