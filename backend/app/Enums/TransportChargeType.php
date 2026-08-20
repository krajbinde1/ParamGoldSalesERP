<?php

namespace App\Enums;

enum TransportChargeType: string
{
    case CompanyTransport = 'company_transport';
    case TransportExtra = 'transport_extra';

    public function label(): string
    {
        return match ($this) {
            self::CompanyTransport => 'Company Transport',
            self::TransportExtra => 'Transport Charges Extra',
        };
    }

    public function adjustmentSign(): int
    {
        return match ($this) {
            self::CompanyTransport => -1,
            self::TransportExtra => 1,
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
