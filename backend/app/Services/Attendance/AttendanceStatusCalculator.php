<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for attendance status from punch times / working duration.
 *
 * Rules (IST):
 * - Punch in only → Punched In
 * - Duration >= 8h → Present
 * - Duration >= 4h and < 8h → Half Day
 * - Duration < 4h → Absent
 */
final class AttendanceStatusCalculator
{
    public const STATUS_PUNCHED_IN = 'Punched In';

    public const STATUS_PRESENT = 'Present';

    public const STATUS_HALF_DAY = 'Half Day';

    public const STATUS_ABSENT = 'Absent';

    public const STATUS_LEAVE = 'Leave';

    public const STATUS_WEEKLY_OFF = 'Weekly Off';

    /** Exact 8:00 hours = Present */
    public const PRESENT_MINUTES = 480;

    /** Exact 4:00 hours = Half Day */
    public const HALF_DAY_MINUTES = 240;

    /** Statuses preserved when recalculating (manual/system special cases). */
    public const PRESERVED_STATUSES = [
        self::STATUS_LEAVE,
        self::STATUS_WEEKLY_OFF,
    ];

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PUNCHED_IN => 'Punched In',
            self::STATUS_PRESENT => 'Present',
            self::STATUS_HALF_DAY => 'Half Day',
            self::STATUS_ABSENT => 'Absent',
            self::STATUS_LEAVE => 'Leave',
            self::STATUS_WEEKLY_OFF => 'Weekly Off',
        ];
    }

    public function calculateFromMinutes(?int $minutes, bool $hasPunchIn, bool $hasPunchOut): string
    {
        if ($hasPunchIn && ! $hasPunchOut) {
            return self::STATUS_PUNCHED_IN;
        }

        if (! $hasPunchIn || $minutes === null) {
            return self::STATUS_ABSENT;
        }

        if ($minutes >= self::PRESENT_MINUTES) {
            return self::STATUS_PRESENT;
        }

        if ($minutes >= self::HALF_DAY_MINUTES) {
            return self::STATUS_HALF_DAY;
        }

        return self::STATUS_ABSENT;
    }

    public function calculate(?string $punchInTime, ?string $punchOutTime, ?string $attendanceDate = null): string
    {
        $hasPunchIn = filled($punchInTime);
        $hasPunchOut = filled($punchOutTime);

        if ($hasPunchIn && ! $hasPunchOut) {
            return self::STATUS_PUNCHED_IN;
        }

        if (! $hasPunchIn || ! $hasPunchOut) {
            return self::STATUS_ABSENT;
        }

        $date = $attendanceDate ?? AttendanceCalendar::today()->toDateString();
        $punchIn = Carbon::parse($date.' '.$punchInTime, AttendanceCalendar::TIMEZONE);
        $punchOut = Carbon::parse($date.' '.$punchOutTime, AttendanceCalendar::TIMEZONE);

        if ($punchOut->lessThan($punchIn)) {
            $punchOut->addDay();
        }

        return $this->calculateFromMinutes(
            $punchIn->diffInMinutes($punchOut),
            true,
            true,
        );
    }

    public function calculateForAttendance(Attendance $attendance): string
    {
        if (in_array($attendance->attendance_status, self::PRESERVED_STATUSES, true)) {
            return (string) $attendance->attendance_status;
        }

        return $this->calculateFromMinutes(
            $attendance->workingDurationMinutes(),
            filled($attendance->punch_in_time),
            filled($attendance->punch_out_time),
        );
    }

    /**
     * Resolve how a calendar working day counts in monthly summaries.
     *
     * Today with open punch stays Punched In (not Absent).
     * Past days with open punch (no punch-out) count as Absent.
     */
    public function classifyWorkingDay(?Attendance $attendance, Carbon $date): string
    {
        if ($attendance === null) {
            return self::STATUS_ABSENT;
        }

        if (in_array($attendance->attendance_status, self::PRESERVED_STATUSES, true)) {
            return (string) $attendance->attendance_status;
        }

        $hasPunchIn = filled($attendance->punch_in_time);
        $hasPunchOut = filled($attendance->punch_out_time);
        $isToday = $date->toDateString() === AttendanceCalendar::today()->toDateString();

        if ($hasPunchIn && ! $hasPunchOut) {
            return $isToday ? self::STATUS_PUNCHED_IN : self::STATUS_ABSENT;
        }

        return $this->calculateFromMinutes(
            $attendance->workingDurationMinutes(),
            $hasPunchIn,
            $hasPunchOut,
        );
    }

    public function formatWorkingHoursLabel(?Attendance $attendance): string
    {
        if ($attendance === null) {
            return '-';
        }

        if (filled($attendance->punch_in_time) && blank($attendance->punch_out_time)) {
            return 'Running';
        }

        $minutes = $attendance->workingDurationMinutes();

        if ($minutes === null) {
            return $attendance->working_hours ?: '-';
        }

        return $this->formatMinutes($minutes);
    }

    public function formatMinutes(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $mins = max(0, $minutes) % 60;

        return "{$hours}h ".str_pad((string) $mins, 2, '0', STR_PAD_LEFT).'m';
    }

    public function shouldAutoRecalculate(?string $currentStatus): bool
    {
        return ! in_array($currentStatus, self::PRESERVED_STATUSES, true);
    }
}
