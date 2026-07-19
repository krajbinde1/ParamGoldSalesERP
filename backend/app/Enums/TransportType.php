<?php

namespace App\Enums;

enum TransportType: string
{
    case CompanyTransport = 'company_transport';
    case OutsideTransport = 'outside_transport';

    public function label(): string
    {
        return match ($this) {
            self::CompanyTransport => 'Company Transport',
            self::OutsideTransport => 'Outside Transport',
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
