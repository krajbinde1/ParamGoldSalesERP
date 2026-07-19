<?php

namespace App\Support;

use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceAdminMonthlyReport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentRows(int $employeeId, int $month, int $year): array
    {
        $records = self::recordsForPeriod($employeeId, $month, $year);

        return $records
            ->groupBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString())
            ->map(function (Collection $dateRecords, string $date): ?array {
                $best = self::bestRecordForDate($dateRecords);
                $minutes = $best?->workingDurationMinutes();

                if ($minutes === null || $minutes < Attendance::ADMIN_MIN_PRESENT_MINUTES) {
                    return null;
                }

                return [
                    'attendance_id' => $best->id,
                    'attendance_date' => $date,
                    'punch_in_time' => $best->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                    'punch_out_time' => $best->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                    'working_hours' => $best->working_hours ?? '-',
                    'status' => $best->attendance_status,
                ];
            })
            ->filter()
            ->sortKeys()
            ->values()
            ->all();
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

        $rows = [];

        for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
            if ($date->isSunday()) {
                continue;
            }

            $dateKey = $date->toDateString();
            $dateRecords = $records->get($dateKey, collect());

            if (self::isPresentDate($dateRecords)) {
                continue;
            }

            $rows[] = [
                'absent_date' => $dateKey,
                'day_name' => $date->format('l'),
                'reason' => self::absentReason($dateRecords),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function monthlyDetailRows(int $employeeId, int $month, int $year): array
    {
        return self::recordsForPeriod($employeeId, $month, $year)
            ->sortBy(fn (Attendance $attendance): string => $attendance->attendance_date->toDateString().' '.$attendance->id)
            ->map(fn (Attendance $attendance): array => [
                'attendance_id' => $attendance->id,
                'attendance_date' => $attendance->attendance_date->toDateString(),
                'punch_in_time' => $attendance->punchInAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'punch_out_time' => $attendance->punchOutAt()?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A') ?? '-',
                'working_hours' => $attendance->working_hours ?? '-',
                'status' => $attendance->attendance_status,
                'approval_status' => $attendance->approval_status,
            ])
            ->values()
            ->all();
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

    private static function isPresentDate(Collection $dateRecords): bool
    {
        $longestMinutes = $dateRecords
            ->map(fn (Attendance $attendance): ?int => $attendance->workingDurationMinutes())
            ->filter()
            ->max();

        return $longestMinutes !== null
            && $longestMinutes >= Attendance::ADMIN_MIN_PRESENT_MINUTES;
    }

    private static function bestRecordForDate(Collection $dateRecords): ?Attendance
    {
        return $dateRecords
            ->sortByDesc(fn (Attendance $attendance): int => $attendance->workingDurationMinutes() ?? -1)
            ->first();
    }

    private static function absentReason(Collection $dateRecords): string
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
            return 'Punch Out Missing';
        }

        return 'Less Than 8 Hours';
    }
}
