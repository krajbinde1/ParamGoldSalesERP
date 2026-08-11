<?php

namespace App\Support;

use App\Models\Attendance;
use App\Services\Attendance\AttendanceStatusCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceAdminMonthlyReport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentRows(int $employeeId, int $month, int $year): array
    {
        return self::statusRows($employeeId, $month, $year, AttendanceStatusCalculator::STATUS_PRESENT);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function halfDayRows(int $employeeId, int $month, int $year): array
    {
        return self::statusRows($employeeId, $month, $year, AttendanceStatusCalculator::STATUS_HALF_DAY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function absentRows(int $employeeId, int $month, int $year): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $records = self::recordsForPeriod($employeeId, $month, $year)
            ->groupBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString());
        $calculator = app(AttendanceStatusCalculator::class);

        $rows = [];

        for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
            if ($date->isSunday()) {
                continue;
            }

            $dateKey = $date->toDateString();
            $dateRecords = $records->get($dateKey, collect());
            $best = self::bestRecordForDate($dateRecords);
            $classification = $calculator->classifyWorkingDay($best, $date);

            if ($classification !== AttendanceStatusCalculator::STATUS_ABSENT) {
                continue;
            }

            $rows[] = [
                'absent_date' => $dateKey,
                'day_name' => $date->format('l'),
                'reason' => self::absentReason($dateRecords, $date),
            ];
        }

        return $rows;
    }

    /**
     * Date-wise attendance for the month (includes missing working days as Absent).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthlyDetailRows(int $employeeId, int $month, int $year): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $records = self::recordsForPeriod($employeeId, $month, $year)
            ->groupBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString());
        $calculator = app(AttendanceStatusCalculator::class);

        $rows = [];

        for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
            if ($date->isSunday()) {
                continue;
            }

            $dateKey = $date->toDateString();
            $best = self::bestRecordForDate($records->get($dateKey, collect()));
            $status = $calculator->classifyWorkingDay($best, $date);

            $rows[] = [
                'attendance_id' => $best?->id,
                'attendance_date' => $dateKey,
                'punch_in_time' => $best?->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'punch_out_time' => $best?->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'working_hours' => $calculator->formatWorkingHoursLabel($best),
                'status' => $status,
                'approval_status' => $best?->approval_status ?? '-',
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function statusRows(int $employeeId, int $month, int $year, string $status): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $records = self::recordsForPeriod($employeeId, $month, $year)
            ->groupBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString());
        $calculator = app(AttendanceStatusCalculator::class);

        $rows = [];

        for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
            if ($date->isSunday()) {
                continue;
            }

            $dateKey = $date->toDateString();
            $best = self::bestRecordForDate($records->get($dateKey, collect()));
            $classification = $calculator->classifyWorkingDay($best, $date);

            if ($classification !== $status || $best === null) {
                continue;
            }

            $rows[] = [
                'attendance_id' => $best->id,
                'attendance_date' => $dateKey,
                'punch_in_time' => $best->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'punch_out_time' => $best->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'working_hours' => $calculator->formatWorkingHoursLabel($best),
                'status' => $classification,
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, Attendance>
     */
    private static function recordsForPeriod(int $employeeId, int $month, int $year): Collection
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);

        return Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->get();
    }

    private static function bestRecordForDate(Collection $dateRecords): ?Attendance
    {
        if ($dateRecords->isEmpty()) {
            return null;
        }

        return $dateRecords
            ->sortByDesc(fn (Attendance $attendance): int => $attendance->workingDurationMinutes() ?? -1)
            ->first();
    }

    private static function absentReason(Collection $dateRecords, Carbon $date): string
    {
        if ($dateRecords->isEmpty()) {
            return 'No Attendance';
        }

        $hasPunchIn = $dateRecords->contains(
            fn (Attendance $attendance): bool => filled($attendance->punch_in_time),
        );

        if (! $hasPunchIn) {
            return 'No Attendance';
        }

        $hasCompletePunch = $dateRecords->contains(
            fn (Attendance $attendance): bool => filled($attendance->punch_in_time) && filled($attendance->punch_out_time),
        );

        if (! $hasCompletePunch) {
            return $date->toDateString() === AttendanceCalendar::today()->toDateString()
                ? 'Punched In'
                : 'Punch Out Missing';
        }

        $minutes = $dateRecords
            ->map(fn (Attendance $attendance): ?int => $attendance->workingDurationMinutes())
            ->filter()
            ->max();

        if ($minutes !== null && $minutes < AttendanceStatusCalculator::HALF_DAY_MINUTES) {
            return 'Less Than 4 Hours';
        }

        return 'Less Than 8 Hours';
    }
}
