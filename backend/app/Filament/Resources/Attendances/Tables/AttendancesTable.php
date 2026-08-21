<?php

namespace App\Filament\Resources\Attendances\Tables;

use App\Models\Attendance;
use App\Filament\Support\EmployeeSelect;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        $calculator = app(AttendanceStatusCalculator::class);

        return $table
            ->heading("Today's Attendance (IST)")
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->formatStateUsing(fn (Attendance $record): string => $record->employee?->displayLabel() ?? '-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attendance_date')
                    ->label('Attendance Date')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('punch_in_time')
                    ->label('Punch In Time (IST)')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('punch_out_time')
                    ->label('Punch Out Time (IST)')
                    ->formatStateUsing(fn (Attendance $record): string => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('working_hours')
                    ->label('Working Hours')
                    ->state(fn (Attendance $record): string => $calculator->formatWorkingHoursLabel($record)),
                TextColumn::make('attendance_status')
                    ->label('Attendance Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AttendanceStatusCalculator::STATUS_PRESENT => 'success',
                        AttendanceStatusCalculator::STATUS_ABSENT => 'danger',
                        AttendanceStatusCalculator::STATUS_HALF_DAY => 'warning',
                        AttendanceStatusCalculator::STATUS_PUNCHED_IN => 'info',
                        AttendanceStatusCalculator::STATUS_LEAVE => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('approval_status')
                    ->label('Approval Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                Filter::make('punched_in')
                    ->label('Punched In')
                    ->toggle()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['isActive'] ?? false)) {
                            return $query;
                        }

                        return $query->whereNotNull('punch_in_time');
                    })
                    ->indicateUsing(fn (array $data): ?string => ($data['isActive'] ?? false) ? 'Punched In' : null),
                SelectFilter::make('attendance_status')
                    ->label('Attendance Status')
                    ->options(Attendance::ATTENDANCE_STATUS_LABELS),
                SelectFilter::make('approval_status')
                    ->label('Approval Status')
                    ->options(Attendance::APPROVAL_STATUS_LABELS),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
