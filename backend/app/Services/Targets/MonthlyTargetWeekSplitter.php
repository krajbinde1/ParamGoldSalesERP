<?php

namespace App\Services\Targets;

use Illuminate\Support\Carbon;

final class MonthlyTargetWeekSplitter
{
    public const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    /**
     * Monday–Sunday weeks clipped to the selected calendar month.
     *
     * @return list<array{start: Carbon, end: Carbon, days: int}>
     */
    public function weeksForMonth(Carbon|string $monthStart): array
    {
        $monthStart = $this->startOfMonth($monthStart);
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        $weeks = [];
        $cursor = $monthStart->copy();

        while ($cursor->lte($monthEnd)) {
            $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
            if ($weekEnd->gt($monthEnd)) {
                $weekEnd = $monthEnd->copy();
            }

            $days = (int) $cursor->diffInDays($weekEnd) + 1;

            $weeks[] = [
                'start' => $cursor->copy(),
                'end' => $weekEnd,
                'days' => $days,
            ];

            $cursor = $weekEnd->copy()->addDay();
        }

        return $weeks;
    }

    /**
     * Split a monthly total across week day-counts so the parts sum exactly to $total.
     *
     * @param  list<int>  $days
     * @return list<int>
     */
    public function allocateUnits(int $totalUnits, array $days): array
    {
        $weekCount = count($days);
        if ($weekCount === 0) {
            return [];
        }

        $totalDays = (int) array_sum($days);
        if ($totalDays <= 0) {
            return array_fill(0, $weekCount, 0);
        }

        $shares = [];
        $remainders = [];
        $assigned = 0;

        foreach ($days as $index => $dayCount) {
            $exact = ($totalUnits * $dayCount) / $totalDays;
            $share = (int) floor($exact);
            $shares[$index] = $share;
            $remainders[$index] = $exact - $share;
            $assigned += $share;
        }

        $leftover = $totalUnits - $assigned;
        $order = range(0, $weekCount - 1);
        usort($order, function (int $left, int $right) use ($remainders): int {
            $compare = $remainders[$right] <=> $remainders[$left];

            return $compare !== 0 ? $compare : $left <=> $right;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i]]++;
        }

        return array_map(intval(...), $shares);
    }

    /**
     * @param  list<int>  $days
     * @return list<string> decimal strings with 2 places
     */
    public function allocateMoney(float $total, array $days): array
    {
        $paise = (int) round($total * 100);
        $shares = $this->allocateUnits($paise, $days);

        return array_map(
            fn (int $units): string => number_format($units / 100, 2, '.', ''),
            $shares,
        );
    }

    public function startOfMonth(Carbon|string $monthStart): Carbon
    {
        return Carbon::parse($monthStart, self::BUSINESS_TIMEZONE)->startOfMonth()->startOfDay();
    }
}
