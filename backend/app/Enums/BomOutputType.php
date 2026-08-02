<?php

namespace App\Enums;

enum BomOutputType: string
{
    case FinishedProduct = 'finished_product';
    case SemiFinished = 'semi_finished';

    public function label(): string
    {
        return match ($this) {
            self::FinishedProduct => 'Finished Product',
            self::SemiFinished => 'Semi-Finished',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
