<?php

namespace App\Enums;

enum TransportFreightLedgerType: string
{
    case Charge = 'charge';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Transport/Freight Charge',
            self::Reversal => 'Transport/Freight Reversal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Charge => 'success',
            self::Reversal => 'danger',
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
