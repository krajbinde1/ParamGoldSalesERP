<?php

namespace App\Filament\Resources\Targets\Tables;

use App\Actions\Targets\DeleteTarget;
use App\Filament\Support\EmployeeSelect;
use App\Models\MonthlyTarget;
use App\Models\WeeklyTarget;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
                TextColumn::make('target_type')
                    ->label('Type')
                    ->badge()
                    ->state(fn (WeeklyTarget $record): string => $record->isGeneratedFromMonthly() ? 'Monthly' : 'Weekly')
                    ->color(fn (WeeklyTarget $record): string => $record->isGeneratedFromMonthly() ? 'info' : 'gray'),
                TextColumn::make('week_period')
                    ->label('Period / Month')
                    ->state(function (WeeklyTarget $record): string {
                        if ($record->monthlyTarget !== null) {
                            return $record->monthlyTarget->monthLabel();
                        }

                        return $record->week_start_date->format('F Y');
                    }),
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
                SelectFilter::make('target_type')
                    ->label('Type')
                    ->options([
                        MonthlyTarget::WEEKLY_TYPE => 'Weekly',
                        MonthlyTarget::TYPE => 'Monthly',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            MonthlyTarget::TYPE => $query->whereNotNull('monthly_target_id'),
                            MonthlyTarget::WEEKLY_TYPE => $query->whereNull('monthly_target_id'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->label('Delete')
                    ->requiresConfirmation()
                    ->modalHeading('Are you sure you want to delete this target?')
                    ->modalDescription('')
                    ->modalSubmitActionLabel('Delete')
                    ->using(function (WeeklyTarget $record): void {
                        app(DeleteTarget::class)->execute($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Are you sure you want to delete this target?')
                        ->modalDescription('Monthly targets will also remove their auto-generated weekly targets. Manually created unrelated targets are not affected.')
                        ->using(function (Collection $records): void {
                            app(DeleteTarget::class)->executeMany($records);
                        }),
                ]),
            ])
            ->defaultSort(
                fn (Builder $query): Builder => $query
                    ->orderByDesc('week_start_date')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id'),
                'desc',
            );
    }
}
