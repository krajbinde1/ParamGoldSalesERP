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
     * Short name used in “Formula For …” field labels (Litre → Ltr).
     */
    public function formulaShortName(): string
    {
        return match ($this) {
            self::Litre, self::Ml => 'Ltr',
            default => $this->value,
        };
    }

    public function usesCountFormulaLabel(): bool
    {
        return in_array($this, [self::Nos, self::Piece], true);
    }

    public static function tryFromMixed(mixed $unit): ?self
    {
        if ($unit instanceof self) {
            return $unit;
        }

        $raw = trim((string) ($unit instanceof \BackedEnum ? $unit->value : $unit));
        if ($raw === '') {
            return null;
        }

        $direct = self::tryFrom($raw);
        if ($direct instanceof self) {
            return $direct;
        }

        return match (strtolower($raw)) {
            'ltr', 'l', 'liter', 'litre' => self::Litre,
            'kg', 'kgs' => self::Kg,
            default => null,
        };
    }

    /**
     * BOM formula quantity field label from the selected batch unit.
     */
    public static function formulaFieldLabel(mixed $batchUnit): string
    {
        $unit = self::tryFromMixed($batchUnit);
        if ($unit === null || $unit->usesCountFormulaLabel()) {
            return 'Formula For Quantity';
        }

        return 'Formula For '.$unit->formulaShortName();
    }

    public static function formulaFieldHelper(mixed $outputType, mixed $batchUnit): string
    {
        $unit = self::tryFromMixed($batchUnit);
        $output = $outputType instanceof \BackedEnum ? $outputType->value : (string) ($outputType ?? '');
        $isSemiFinished = $output === 'semi_finished';

        if ($isSemiFinished && $unit !== null && ! $unit->usesCountFormulaLabel()) {
            $short = $unit->formulaShortName();

            return 'Total '.$short.' of semi-finished output this BOM formula produces.';
        }

        if ($isSemiFinished) {
            return 'Number of semi-finished output units this BOM formula produces.';
        }

        return 'Number of finished packs this packing recipe is for (e.g. 1 Nos of 5 KG bags).';
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
