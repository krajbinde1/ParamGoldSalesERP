<?php

namespace App\Filament\Resources\Attendances\Widgets;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceCalendar;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class MonthlyAttendanceSummary extends BaseWidget
{
    protected static ?string $heading = 'Employee Monthly Summary (IST)';

    protected int|string|array $columnSpan = 'full';

    public ?int $summaryMonth = null;

    public ?int $summaryYear = null;

    /** @var array<int, array<string, int>> */
    private array $summaryCache = [];

    public function mount(): void
    {
        $now = AttendanceCalendar::now();
        $this->summaryMonth ??= $now->month;
        $this->summaryYear ??= $now->year;
    }

    public function table(Table $table): Table
    {
        $month = (int) ($this->summaryMonth ?? AttendanceCalendar::now()->month);
        $year = (int) ($this->summaryYear ?? AttendanceCalendar::now()->year);
        $monthLabel = AttendanceCalendar::now()->month($month)->format('F');

        return $table
            ->query(fn (): Builder => Employee::query()->orderBy('full_name'))
            ->heading(self::$heading.' — '.$monthLabel.' '.$year)
            ->headerActions([
                Action::make('previousMonth')
                    ->label('Previous')
                    ->icon('heroicon-o-chevron-left')
                    ->color('gray')
                    ->action(fn () => $this->shiftMonth(-1)),
                Action::make('pickPeriod')
                    ->label($monthLabel.' '.$year)
                    ->icon('heroicon-o-calendar-days')
                    ->color('gray')
                    ->form([
                        Select::make('summary_month')
                            ->label('Month')
                            ->options($this->monthOptions())
                            ->default($month)
                            ->required(),
                        Select::make('summary_year')
                            ->label('Year')
                            ->options($this->yearOptions())
                            ->default($year)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->summaryMonth = (int) $data['summary_month'];
                        $this->summaryYear = (int) $data['summary_year'];
                        $this->summaryCache = [];
                        $this->resetTable();
                    }),
                Action::make('nextMonth')
                    ->label('Next')
                    ->icon('heroicon-o-chevron-right')
                    ->color('gray')
                    ->disabled(fn (): bool => $this->isCurrentOrFuturePeriod())
                    ->action(fn () => $this->shiftMonth(1)),
            ])
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
                    ]).'?month='.$month.'&year='.$year),
                TextColumn::make('half_days')
                    ->label('Half Days')
                    ->state(fn (Employee $record): int => $this->summaryFor($record, $month, $year)['half_days'])
                    ->color('warning')
                    ->url(fn (Employee $record): string => AttendanceResource::getUrl('employee-half-days', [
                        'employee' => $record,
                    ]).'?month='.$month.'&year='.$year),
                TextColumn::make('absent_days')
                    ->label('Absent Days')
                    ->state(fn (Employee $record): int => $this->summaryFor($record, $month, $year)['absent_days'])
                    ->color('danger')
                    ->url(fn (Employee $record): string => AttendanceResource::getUrl('employee-absent-days', [
                        'employee' => $record,
                    ]).'?month='.$month.'&year='.$year),
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

    public function shiftMonth(int $delta): void
    {
        $cursor = AttendanceCalendar::now()
            ->month((int) $this->summaryMonth)
            ->year((int) $this->summaryYear)
            ->startOfMonth()
            ->addMonths($delta);

        if ($cursor->greaterThan(AttendanceCalendar::today()->startOfMonth())) {
            return;
        }

        $this->summaryMonth = $cursor->month;
        $this->summaryYear = $cursor->year;
        $this->summaryCache = [];
        $this->resetTable();
    }

    private function isCurrentOrFuturePeriod(): bool
    {
        $cursor = AttendanceCalendar::now()
            ->month((int) $this->summaryMonth)
            ->year((int) $this->summaryYear)
            ->startOfMonth();

        return $cursor->greaterThanOrEqualTo(AttendanceCalendar::today()->startOfMonth());
    }

    /**
     * @return array{employee_id: int, month: int, year: int, working_days: int, present_days: int, half_days: int, absent_days: int}
     */
    private function summaryFor(Employee $record, int $month, int $year): array
    {
        return $this->summaryCache[$record->id] ??= Attendance::adminEmployeeMonthlySummary(
            $record->id,
            $month,
            $year,
        );
    }

    /**
     * @return array<int, string>
     */
    private function monthOptions(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn (int $month): array => [$month => AttendanceCalendar::now()->month($month)->format('F')])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        $currentYear = AttendanceCalendar::now()->year;

        return collect(range($currentYear - 5, $currentYear))
            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
            ->all();
    }
}
