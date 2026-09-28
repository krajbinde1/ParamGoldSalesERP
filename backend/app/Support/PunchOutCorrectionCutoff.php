<?php

namespace App\Support;

use App\Models\AttendancePunchOutCorrection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PunchOutCorrectionCutoff
{
    public static function at(): Carbon
    {
        return Carbon::parse(
            (string) config('attendance.punch_out_correction_cutoff', '2026-09-28 00:00:00'),
            AttendanceCalendar::TIMEZONE,
        );
    }

    public static function includes(AttendancePunchOutCorrection $correction): bool
    {
        $createdAt = $correction->created_at?->copy()->timezone(AttendanceCalendar::TIMEZONE);

        return $createdAt !== null && $createdAt->greaterThanOrEqualTo(self::at());
    }

    /**
     * @param  Builder<AttendancePunchOutCorrection>  $query
     * @return Builder<AttendancePunchOutCorrection>
     */
    public static function constrainPending(Builder $query): Builder
    {
        return $query
            ->where('status', AttendancePunchOutCorrection::STATUS_PENDING)
            ->where('created_at', '>=', self::at());
    }
}
