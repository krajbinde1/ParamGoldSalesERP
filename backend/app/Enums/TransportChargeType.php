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

    public static function tryNormalize(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $direct = self::tryFrom($trimmed);
        if ($direct !== null) {
            return $direct;
        }

        $normalized = strtolower($trimmed);
        $normalized = str_replace(['-', ' '], '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'company_transport', 'companytransport', 'company' => self::CompanyTransport,
            'transport_extra',
            'transportextra',
            'transport_charges_extra',
            'transport_charge_extra',
            'transportchargesextra',
            'extra' => self::TransportExtra,
            'outside_transport', 'outsidetransport', 'outside' => self::TransportExtra,
            default => self::tryFrom($normalized),
        };
    }
}
