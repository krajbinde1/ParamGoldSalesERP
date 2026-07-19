<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\Attendances\Tables\EmployeeMonthlyDetailsTable;
use App\Models\Attendance;
use App\Models\Employee;
use App\Support\AttendanceCalendar;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class EmployeeMonthlyDetails extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = AttendanceResource::class;

    protected static ?string $title = 'Monthly Attendance';

    protected static ?string $navigationLabel = 'Monthly Attendance';

    protected string $view = 'filament.resources.attendances.employee-monthly-details';

    public Employee $employee;

    public int $month;

    public int $year;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        $this->month = (int) request()->query('month', AttendanceCalendar::now()->month);
        $this->year = (int) request()->query('year', AttendanceCalendar::now()->year);
    }

    public function getHeading(): string|Htmlable
    {
        return 'Employee Monthly Attendance';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $monthLabel = AttendanceCalendar::now()->month($this->month)->format('F');

        return new HtmlString(
            e($this->employee->full_name).'<br>'.e($monthLabel.' '.$this->year),
        );
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            AttendanceResource::getUrl('index') => 'Attendances',
            url()->current() => 'Monthly Attendance',
        ];
    }

    public function table(Table $table): Table
    {
        return EmployeeMonthlyDetailsTable::configure($table)
            ->query(fn (): Builder => $this->getTableQuery());
    }

    protected function getTableQuery(): Builder
    {
        $periodStart = Carbon::create($this->year, $this->month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($this->month, $this->year);

        return Attendance::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->orderBy('attendance_date')
            ->orderBy('id');
    }
}
