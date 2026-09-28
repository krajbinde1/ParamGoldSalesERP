<?php

namespace App\Support;

use App\Models\Attendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PunchOutEnforcementCutoff
{
    public static function at(): Carbon
    {
        return Carbon::parse(
            (string) config('attendance.punch_out_enforcement_cutoff', '2026-09-29 00:00:00'),
            AttendanceCalendar::TIMEZONE,
        );
    }

    public static function includes(Attendance $attendance): bool
    {
        $punchIn = $attendance->punchInAt();

        return $punchIn !== null && $punchIn->greaterThanOrEqualTo(self::at());
    }

    /**
     * Open attendance punched in before the rollout. It stays in history and
     * must not drive the >24-hour correction workflow.
     */
    public static function isHistoricalOpen(Attendance $attendance): bool
    {
        if (filled($attendance->punch_out_time) || blank($attendance->punch_in_time)) {
            return false;
        }

        $punchIn = $attendance->punchInAt();

        return $punchIn !== null && $punchIn->lessThan(self::at());
    }

    /**
     * @param  Builder<Attendance>  $query
     * @return Builder<Attendance>
     */
    public static function constrainEnforced(Builder $query): Builder
    {
        $cutoff = self::at();
        $date = $cutoff->toDateString();
        $time = $cutoff->format('H:i:s');

        return $query->where(function (Builder $inner) use ($date, $time): void {
            $inner->whereDate('attendance_date', '>', $date)
                ->orWhere(function (Builder $sameDay) use ($date, $time): void {
                    $sameDay->whereDate('attendance_date', $date)
                        ->where('punch_in_time', '>=', $time);
                });
        });
    }
}
