<?php

namespace App\Enums;

enum PurchaseFreightType: string
{
    case TotalFreight = 'total_freight';
    case FreightPerTon = 'freight_per_ton';

    public function label(): string
    {
        return match ($this) {
            self::TotalFreight => 'Total Freight',
            self::FreightPerTon => 'Freight Per Ton',
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

    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return self::tryFrom((string) $value) ?? self::TotalFreight;
    }
}
