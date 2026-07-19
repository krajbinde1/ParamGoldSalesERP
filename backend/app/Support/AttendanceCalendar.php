<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class AttendanceCalendar
{
    public const TIMEZONE = 'Asia/Kolkata';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    public static function workingDaysInPeriod(Carbon $start, Carbon $end): int
    {
        $days = 0;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (! $date->isSunday()) {
                $days++;
            }
        }

        return $days;
    }

    public static function periodEndForMonth(int $month, int $year): Carbon
    {
        $today = self::today();
        $monthEnd = Carbon::create($year, $month, 1, 0, 0, 0, self::TIMEZONE)->endOfMonth()->startOfDay();

        return $monthEnd->greaterThan($today) ? $today : $monthEnd;
    }
}
