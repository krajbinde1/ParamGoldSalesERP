<?php

namespace App\Filament\Resources\DealerApplications\Tables;

use App\Filament\Support\EmployeeSelect;
use App\Models\DealerApplication;
use App\Models\Employee;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealerApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('firm_name')
                    ->label('Dealer Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner_name')
                    ->searchable(),
                TextColumn::make('mobile')
                    ->searchable(),
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (DealerApplication $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable(),
                TextColumn::make('employee.reportingManager.full_name')
                    ->label('Manager')
                    ->placeholder('—'),
                TextColumn::make('state')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('district')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DealerApplication::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        DealerApplication::STATUS_DRAFT => 'gray',
                        DealerApplication::STATUS_PENDING_MANAGER => 'warning',
                        DealerApplication::STATUS_PENDING_ADMIN => 'info',
                        DealerApplication::STATUS_CORRECTION_REQUIRED => 'warning',
                        DealerApplication::STATUS_REJECTED => 'danger',
                        DealerApplication::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('dealer.dealer_code')
                    ->label('Dealer Code')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('duplicate_warning')
                    ->label('Duplicate?')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('d M Y h:i A')
                    ->timezone('Asia/Kolkata')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                EmployeeSelect::applyRelationshipFilter(
                    SelectFilter::make('employee_id')
                        ->label('Employee')
                        ->relationship('employee', 'full_name')
                        ->searchable()
                        ->preload(),
                ),
                SelectFilter::make('manager')
                    ->label('Manager')
                    ->options(
                        fn (): array => Employee::query()
                            ->whereHas('directReports')
                            ->orderBy('full_name')
                            ->get()
                            ->mapWithKeys(fn (Employee $employee): array => [
                                $employee->id => $employee->displayLabel(),
                            ])
                            ->all()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        if (! filled($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'employee',
                            fn (Builder $employeeQuery) => $employeeQuery->where('reporting_manager_id', $data['value']),
                        );
                    }),
                SelectFilter::make('state')
                    ->options(
                        fn (): array => DealerApplication::query()
                            ->whereNotNull('state')
                            ->distinct()
                            ->orderBy('state')
                            ->pluck('state', 'state')
                            ->all()
                    ),
                SelectFilter::make('district')
                    ->options(
                        fn (): array => DealerApplication::query()
                            ->whereNotNull('district')
                            ->distinct()
                            ->orderBy('district')
                            ->pluck('district', 'district')
                            ->all()
                    ),
                SelectFilter::make('status')
                    ->options(DealerApplication::STATUS_LABELS),
                Filter::make('submitted_at')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('To'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $inner): Builder => $inner->whereDate('submitted_at', '>=', $data['from']),
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $inner): Builder => $inner->whereDate('submitted_at', '<=', $data['until']),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
