<?php

namespace App\Enums;

enum InventoryUnit: string
{
    case Kg = 'Kg';
    case Gram = 'Gram';
    case Litre = 'Litre';
    case Ml = 'Ml';
    case Nos = 'Nos';
    case Piece = 'Piece';
    case Bag = 'Bag';
    case Packet = 'Packet';
    case Box = 'Box';
    case Drum = 'Drum';
    case Ton = 'Ton';
    case Bottle = 'Bottle';

    public function label(): string
    {
        return match ($this) {
            self::Ml => 'Millilitre',
            self::Nos => 'Nos',
            default => $this->value,
        };
    }

    public function family(): string
    {
        return match ($this) {
            self::Ton, self::Kg, self::Gram => 'weight',
            self::Litre, self::Ml => 'volume',
            default => 'count',
        };
    }

    /**
     * Formulation units compatible with this inventory stock unit.
     *
     * @return array<string, string>
     */
    public function formulationOptions(): array
    {
        $cases = match ($this->family()) {
            'weight' => [self::Ton, self::Kg, self::Gram],
            'volume' => [self::Litre, self::Ml],
            default => in_array($this, [self::Nos, self::Piece], true)
                ? [self::Nos, self::Piece]
                : [$this],
        };

        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
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

    /**
     * BOM Formula For Quantity units (Kg / Litre / Nos only).
     *
     * @return array<string, string>
     */
    public static function batchUnitOptions(): array
    {
        return [
            self::Kg->value => self::Kg->label(),
            self::Litre->value => self::Litre->label(),
            self::Nos->value => self::Nos->label(),
            self::Piece->value => self::Piece->label(),
        ];
    }
}
