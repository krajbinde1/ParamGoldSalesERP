<?php

namespace App\Services\Inventory;

use App\Enums\InventoryUnit;
use Illuminate\Validation\ValidationException;

/**
 * Converts between compatible formulation units and inventory stock units.
 *
 * Weight base: Gram. Volume base: Millilitre. Count: same-unit only (1:1).
 */
final class InventoryUnitConversion
{
    public const FAMILY_WEIGHT = 'weight';

    public const FAMILY_VOLUME = 'volume';

    public const FAMILY_COUNT = 'count';

    /**
     * Grams per unit for weight family.
     *
     * @var array<string, float>
     */
    private const WEIGHT_TO_GRAM = [
        'Ton' => 1_000_000.0,
        'Kg' => 1_000.0,
        'Gram' => 1.0,
    ];

    /**
     * Millilitres per unit for volume family.
     *
     * @var array<string, float>
     */
    private const VOLUME_TO_ML = [
        'Litre' => 1_000.0,
        'Ml' => 1.0,
    ];

    /**
     * Normalize aliases (Piece → Nos).
     */
    public function normalize(string $unit): string
    {
        $trimmed = trim($unit);

        return match (mb_strtolower($trimmed)) {
            'piece', 'pcs', 'pc' => InventoryUnit::Piece->value,
            'nos', 'no', 'number' => InventoryUnit::Nos->value,
            'millilitre', 'milliliter', 'ml' => InventoryUnit::Ml->value,
            'liter', 'litre', 'l' => InventoryUnit::Litre->value,
            'kilogram', 'kilogramme', 'kg' => InventoryUnit::Kg->value,
            'gram', 'g' => InventoryUnit::Gram->value,
            'tonne', 'ton', 't' => InventoryUnit::Ton->value,
            'bottle' => InventoryUnit::Bottle->value,
            default => InventoryUnit::tryFrom($trimmed)?->value ?? $trimmed,
        };
    }

    public function family(string $unit): ?string
    {
        $unit = $this->normalize($unit);

        if (isset(self::WEIGHT_TO_GRAM[$unit])) {
            return self::FAMILY_WEIGHT;
        }
        if (isset(self::VOLUME_TO_ML[$unit])) {
            return self::FAMILY_VOLUME;
        }
        if (in_array($unit, [
            InventoryUnit::Nos->value,
            InventoryUnit::Piece->value,
            InventoryUnit::Bag->value,
            InventoryUnit::Packet->value,
            InventoryUnit::Box->value,
            InventoryUnit::Bottle->value,
            InventoryUnit::Drum->value,
        ], true)) {
            return self::FAMILY_COUNT;
        }

        return null;
    }

    public function areCompatible(string $fromUnit, string $toUnit): bool
    {
        $from = $this->normalize($fromUnit);
        $to = $this->normalize($toUnit);
        $fromFamily = $this->family($from);
        $toFamily = $this->family($to);

        if ($fromFamily === null || $toFamily === null || $fromFamily !== $toFamily) {
            return false;
        }

        if ($fromFamily === self::FAMILY_COUNT) {
            // Nos and Piece are interchangeable count units (1:1).
            if (
                in_array($from, [InventoryUnit::Nos->value, InventoryUnit::Piece->value], true)
                && in_array($to, [InventoryUnit::Nos->value, InventoryUnit::Piece->value], true)
            ) {
                return true;
            }

            return $from === $to;
        }

        return true;
    }

    /**
     * Factor to multiply `from` quantity by to get `to` quantity.
     * Example: Kg → Ton = 0.001
     */
    public function conversionFactor(string $fromUnit, string $toUnit): float
    {
        $from = $this->normalize($fromUnit);
        $to = $this->normalize($toUnit);

        if (! $this->areCompatible($from, $to)) {
            throw ValidationException::withMessages([
                'unit' => sprintf('%s cannot be converted to %s for this material.', $fromUnit, $toUnit),
            ]);
        }

        if ($from === $to) {
            return 1.0;
        }

        $family = $this->family($from);

        if ($family === self::FAMILY_WEIGHT) {
            return self::WEIGHT_TO_GRAM[$from] / self::WEIGHT_TO_GRAM[$to];
        }

        if ($family === self::FAMILY_VOLUME) {
            return self::VOLUME_TO_ML[$from] / self::VOLUME_TO_ML[$to];
        }

        return 1.0;
    }

    /**
     * @return array{
     *   quantity: float,
     *   from_unit: string,
     *   to_unit: string,
     *   conversion_factor: float
     * }
     */
    public function convert(float $quantity, string $fromUnit, string $toUnit): array
    {
        $from = $this->normalize($fromUnit);
        $to = $this->normalize($toUnit);
        $factor = $this->conversionFactor($from, $to);

        return [
            'quantity' => round($quantity * $factor, 6),
            'from_unit' => $from,
            'to_unit' => $to,
            'conversion_factor' => round($factor, 12),
        ];
    }

    /**
     * Formulation units allowed for a given inventory stock unit.
     *
     * @return array<string, string> value => label
     */
    public function compatibleFormulationOptions(string $inventoryUnit): array
    {
        $inventoryUnit = $this->normalize($inventoryUnit);
        $case = InventoryUnit::tryFrom($inventoryUnit);

        if ($case === null) {
            return [];
        }

        return $case->formulationOptions();
    }

    public function formatQuantity(float $quantity, int $decimals = 3): string
    {
        return number_format($quantity, $decimals, '.', '');
    }
}
