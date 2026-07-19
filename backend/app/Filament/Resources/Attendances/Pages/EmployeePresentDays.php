<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Employee;
use App\Support\AttendanceAdminMonthlyReport;
use App\Support\AttendanceCalendar;
use Filament\Resources\Pages\Page;

class EmployeePresentDays extends Page
{
    protected static string $resource = AttendanceResource::class;

    protected static ?string $title = 'Present Days';

    protected string $view = 'filament.resources.attendances.employee-present-days';

    public Employee $employee;

    public int $month;

    public int $year;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        $this->month = (int) request()->query('month', AttendanceCalendar::now()->month);
        $this->year = (int) request()->query('year', AttendanceCalendar::now()->year);
    }

    public function getHeading(): string
    {
        $monthLabel = AttendanceCalendar::now()->month($this->month)->format('F');

        return "{$this->employee->full_name} - Present Days ({$monthLabel} {$this->year})";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        return AttendanceAdminMonthlyReport::presentRows(
            $this->employee->id,
            $this->month,
            $this->year,
        );
    }
}
