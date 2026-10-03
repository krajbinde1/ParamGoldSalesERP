<?php

use App\Enums\UserRole;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusCalculator;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function attendanceListOrderEmployee(string $name): Employee
{
    static $n = 0;
    $n++;

    return Employee::query()->create([
        'full_name' => $name,
        'mobile' => (string) (9700000000 + $n),
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (410000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'LSTTT'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (410000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);
}

function attendanceListOrderAdmin(): User
{
    return User::query()->create([
        'name' => 'Attendance List Order Admin',
        'email' => 'attendance.list.order.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

/**
 * @return list<int>
 */
function attendanceListOrderedIds(mixed $component): array
{
    return collect($component->instance()->getTableRecords()->items())
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

it('lists attendances by attendance date newest first then latest punch in', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-03 15:00:00', AttendanceCalendar::TIMEZONE));

    try {
        $admin = attendanceListOrderAdmin();

        $todayEarly = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Today Early Punch')->id,
            'attendance_date' => '2026-10-03',
            'punch_in_time' => '09:00:00',
            'approval_status' => 'Pending',
        ]);
        $historical21 = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Historical Open 21 Aug')->id,
            'attendance_date' => '2026-08-21',
            'punch_in_time' => '10:30:00',
            'approval_status' => 'Pending',
        ]);
        $yesterday = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Yesterday Open Punch')->id,
            'attendance_date' => '2026-10-02',
            'punch_in_time' => '08:15:00',
            'approval_status' => 'Pending',
        ]);
        $pendingCorrection = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Pending Correction 26 Sep')->id,
            'attendance_date' => '2026-09-26',
            'punch_in_time' => '09:00:00',
            'punch_out_time' => '18:00:00',
            'approval_status' => 'Pending',
            'punch_out_correction_status' => AttendancePunchOutCorrection::STATUS_PENDING,
        ]);
        AttendancePunchOutCorrection::query()->create([
            'attendance_id' => $pendingCorrection->id,
            'requested_by' => $admin->id,
            'requested_punch_out_at' => Carbon::parse('2026-09-26 18:00:00', AttendanceCalendar::TIMEZONE),
            'reason' => AttendancePunchOutCorrection::REASON_FORGOT,
            'status' => AttendancePunchOutCorrection::STATUS_PENDING,
        ]);
        $todayLate = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Today Late Punch')->id,
            'attendance_date' => '2026-10-03',
            'punch_in_time' => '18:05:00',
            'approval_status' => 'Pending',
        ]);
        $historical23 = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Historical Open 23 Aug')->id,
            'attendance_date' => '2026-08-23',
            'punch_in_time' => '11:00:00',
            'approval_status' => 'Pending',
        ]);
        $closedHistorical = Attendance::query()->create([
            'employee_id' => attendanceListOrderEmployee('Closed Historical 20 Aug')->id,
            'attendance_date' => '2026-08-20',
            'punch_in_time' => '09:00:00',
            'punch_out_time' => '18:00:00',
            'approval_status' => 'Pending',
        ]);

        expect($pendingCorrection->fresh()->attendance_status)->toBe(AttendanceStatusCalculator::STATUS_PRESENT);

        $expected = [
            $todayLate->id,
            $todayEarly->id,
            $yesterday->id,
            $pendingCorrection->id,
            $historical23->id,
            $historical21->id,
        ];

        $page = Livewire::actingAs($admin)->test(ListAttendances::class);

        expect(attendanceListOrderedIds($page))->toBe($expected)
            ->and($page->instance()->getFilteredSortedTableQuery()->toSql())
            ->toContain('order by "attendances"."attendance_date" desc')
            ->toContain('"attendances"."punch_in_time" desc');

        $page->assertCanNotSeeTableRecords([$closedHistorical]);

        $paged = Livewire::actingAs($admin)
            ->test(ListAttendances::class)
            ->set('tableRecordsPerPage', 2);

        expect(attendanceListOrderedIds($paged))->toBe([
            $todayLate->id,
            $todayEarly->id,
        ]);

        $paged->call('gotoPage', 2);

        expect(attendanceListOrderedIds($paged))->toBe([
            $yesterday->id,
            $pendingCorrection->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ListAttendances::class)
            ->set('tableSearch', '')
            ->set('tableFilters', null)
            ->searchTable('Historical Open 21 Aug')
            ->assertCanSeeTableRecords([$historical21])
            ->assertCanNotSeeTableRecords([$todayLate, $todayEarly, $yesterday, $pendingCorrection, $historical23, $closedHistorical])
            ->assertCountTableRecords(1);

        Livewire::actingAs($admin)
            ->test(ListAttendances::class)
            ->set('tableSearch', '')
            ->filterTable('attendance_status', AttendanceStatusCalculator::STATUS_PRESENT)
            ->assertCanSeeTableRecords([$pendingCorrection])
            ->assertCanNotSeeTableRecords([$todayLate, $todayEarly, $yesterday, $historical23, $historical21, $closedHistorical])
            ->assertCountTableRecords(1);
    } finally {
        Carbon::setTestNow();
    }
});
