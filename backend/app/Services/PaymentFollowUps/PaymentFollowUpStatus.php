<?php

namespace App\Services\PaymentFollowUps;

use Illuminate\Support\Carbon;

final class PaymentFollowUpStatus
{
    public const OVERDUE = 'overdue';

    public const DUE_TODAY = 'due_today';

    public const UPCOMING = 'upcoming';

    public const NO_FOLLOW_UP = 'no_follow_up';

    public const CLOSED = 'closed';

    public const TIMEZONE = 'Asia/Kolkata';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::OVERDUE => 'Overdue',
            self::DUE_TODAY => 'Due Today',
            self::UPCOMING => 'Upcoming',
            self::NO_FOLLOW_UP => 'No Follow-up',
            self::CLOSED => 'Closed / Payment Received',
        ];
    }

    public static function label(string $status): string
    {
        return self::labels()[$status] ?? $status;
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }

    public static function priority(string $status): int
    {
        return match ($status) {
            self::OVERDUE => 1,
            self::DUE_TODAY => 2,
            self::UPCOMING => 3,
            self::NO_FOLLOW_UP => 4,
            default => 5,
        };
    }

    public static function fromOpenNextDate(?string $nextFollowUpDate, bool $hasOpenCycle, bool $lastCycleClosed, float $currentOutstanding): string
    {
        if ($hasOpenCycle && $nextFollowUpDate !== null && $nextFollowUpDate !== '') {
            $today = self::todayDate();

            if ($nextFollowUpDate < $today) {
                return self::OVERDUE;
            }

            if ($nextFollowUpDate === $today) {
                return self::DUE_TODAY;
            }

            return self::UPCOMING;
        }

        if ($lastCycleClosed && $currentOutstanding <= 0) {
            return self::CLOSED;
        }

        return self::NO_FOLLOW_UP;
    }
}
