<?php

namespace App\Enums;

enum CompanyTransportEntryKind: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
            self::Reversal => 'Reversal',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Credit => 'success',
            self::Debit => 'danger',
            self::Reversal => 'warning',
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
