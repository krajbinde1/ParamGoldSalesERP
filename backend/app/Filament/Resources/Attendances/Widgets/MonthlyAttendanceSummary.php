<?php

namespace App\Filament\Resources\Attendances\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class MonthlyAttendanceSummary extends BaseWidget
{
    protected static ?string $heading = 'Employee Monthly Summary (IST)';

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, array<string, int>> */
    private array $summaryCache = [];

    public function table(Table $table): Table
    {
        [$month, $year, $employeeId] = $this->resolvePeriod();

        return $table
            ->query(fn (): Builder => $this->employeeQuery($employeeId))
            ->heading(self::$heading.' - '.AttendanceCalendar::now()->month($month)->format('F').' '.$year)
            ->columns([
                TextColumn::make('full_name')
                    ->label('Employee Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('working_days')
                    ->label('Working Days')
                    ->state(fn (Employee $record): int => $this->summaryFor($record, $month, $year)['working_days']),
                TextColumn::make('present_days')
                    ->label('Present Days')
                    ->state(fn (Employee $record): int => $this->summaryFor($record, $month, $year)['present_days'])
                    ->color('success')
                    ->url(fn (Employee $record): string => AttendanceResource::getUrl('employee-present-days', [
                        'employee' => $record,
                    ]).'?month='.$month.'&year='.$year)
                    ->openUrlInNewTab(false),
                TextColumn::make('absent_days')
                    ->label('Absent Days')
                    ->state(fn (Employee $record): int => $this->summaryFor($record, $month, $year)['absent_days'])
                    ->color('danger')
                    ->url(fn (Employee $record): string => AttendanceResource::getUrl('employee-absent-days', [
                        'employee' => $record,
                    ]).'?month='.$month.'&year='.$year)
                    ->openUrlInNewTab(false),
            ])
            ->recordActions([
                Action::make('viewDetails')
                    ->label('View Details')
                    ->url(fn (Employee $record): string => AttendanceResource::getUrl('employee-monthly-details', [
                        'employee' => $record,
                    ]).'?month='.$month.'&year='.$year),
            ])
            ->paginated([10, 25, 50]);
    }

    /**
     * @return array{0: int, 1: int, 2: int|null}
     */
    private function resolvePeriod(): array
    {
        $tableFilters = request()->query('tableFilters', []);
        $month = (int) (
            data_get($tableFilters, 'summary_month.value')
            ?? data_get($tableFilters, 'attendance_month.value')
            ?? AttendanceCalendar::now()->month
        );
        $year = (int) (
            data_get($tableFilters, 'summary_year.value')
            ?? data_get($tableFilters, 'attendance_year.value')
            ?? AttendanceCalendar::now()->year
        );
        $employeeId = data_get($tableFilters, 'employee_id.value') ?? data_get($tableFilters, 'employee_id');
        $employeeId = blank($employeeId) ? null : (int) $employeeId;

        return [$month, $year, $employeeId];
    }

    private function employeeQuery(?int $employeeId): Builder
    {
        return Employee::query()
            ->when($employeeId, fn (Builder $query): Builder => $query->whereKey($employeeId))
            ->orderBy('full_name');
    }

    /**
     * @return array{employee_id: int, month: int, year: int, working_days: int, present_days: int, absent_days: int}
     */
    private function summaryFor(Employee $record, int $month, int $year): array
    {
        return $this->summaryCache[$record->id] ??= Attendance::adminEmployeeMonthlySummary(
            $record->id,
            $month,
            $year,
        );
    }
}
