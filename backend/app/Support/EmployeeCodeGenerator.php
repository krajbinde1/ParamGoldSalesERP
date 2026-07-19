<?php

namespace App\Support;

use App\Models\Employee;

final class EmployeeCodeGenerator
{
    public static function generateNext(): string
    {
        $max = Employee::withTrashed()
            ->whereNotNull('employee_code')
            ->pluck('employee_code')
            ->reduce(function (int $carry, string $code): int {
                if (preg_match('/^E(\d+)/', $code, $matches)) {
                    return max($carry, (int) $matches[1]);
                }

                if (preg_match('/^EMP(\d+)/', $code, $matches)) {
                    return max($carry, (int) $matches[1]);
                }

                return $carry;
            }, 0);

        return self::format($max + 1);
    }

    public static function format(int $number): string
    {
        return 'E'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
