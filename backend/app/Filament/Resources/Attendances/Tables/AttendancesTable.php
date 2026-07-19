<?php

namespace App\Filament\Resources\Attendances\Tables;

use App\Models\Attendance;
use App\Filament\Support\EmployeeSelect;
use App\Support\AttendanceCalendar;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading("Today's Attendance (IST)")
            ->columns([
                TextColumn::make('employee.full_name')->label('Employee')->formatStateUsing(fn (Attendance $record): string => $record->employee?->displayLabel() ?? '-')->searchable()->sortable(),
                TextColumn::make('attendance_date')->label('Attendance Date')->date('d M Y')->sortable(),
                TextColumn::make('punch_in_time')
                    ->label('Punch In Time (IST)')
                    ->formatStateUsing(fn ($record): string => $record->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('punch_out_time')
                    ->label('Punch Out Time (IST)')
                    ->formatStateUsing(fn ($record): string => $record->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-')
                    ->placeholder('-'),
                TextColumn::make('working_hours')->label('Working Hours')->placeholder('-'),
                TextColumn::make('attendance_status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                    'Present' => 'success', 'Absent' => 'danger', 'Half Day' => 'warning', 'Leave' => 'info', default => 'gray'
                }),
                TextColumn::make('approval_status')->label('Approval Status')->badge()->color(fn (string $state): string => match ($state) {
                    'Approved' => 'success', 'Rejected' => 'danger', default => 'warning'
                }),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship('employee', 'full_name')
                    ->tap(fn (SelectFilter $filter) => EmployeeSelect::applyRelationshipFilter($filter))
                    ->preload(),
                SelectFilter::make('summary_month')
                    ->label('Summary Month')
                    ->options(self::monthOptions())
                    ->default(AttendanceCalendar::now()->month)
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('summary_year')
                    ->label('Summary Year')
                    ->options(self::yearOptions())
                    ->default(AttendanceCalendar::now()->year)
                    ->query(fn (Builder $query): Builder => $query),
                SelectFilter::make('attendance_status')->label('Status')->options(Attendance::ATTENDANCE_STATUS_LABELS),
                SelectFilter::make('approval_status')->label('Approval Status')->options(Attendance::APPROVAL_STATUS_LABELS),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    private static function monthOptions(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn (int $month): array => [$month => AttendanceCalendar::now()->month($month)->format('F')])
            ->all();
    }

    private static function yearOptions(): array
    {
        $currentYear = AttendanceCalendar::now()->year;

        return collect(range($currentYear - 2, $currentYear))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }
}
