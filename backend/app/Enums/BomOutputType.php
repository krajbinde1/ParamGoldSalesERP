<?php

namespace App\Enums;

enum BomOutputType: string
{
    case FinishedProduct = 'finished_product';
    case SemiFinished = 'semi_finished';

    public function label(): string
    {
        return match ($this) {
            self::FinishedProduct => 'Packing (Finished Product)',
            self::SemiFinished => 'Manufacturing (Bulk / Semi-Finished)',
        };
    }

    public function helperText(): string
    {
        return match ($this) {
            self::FinishedProduct => 'One packing size / SKU. Consume bulk/semi-finished plus this size’s packing materials. Do not copy the raw-material recipe here.',
            self::SemiFinished => 'Shared manufacturing formula. Raw materials go into bulk once; packing sizes consume that bulk.',
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
