<?php

namespace App\Services\Inventory;

use App\Enums\InventoryUnit;

/**
 * Display Effective Rate as ₹ per Kg for weight-based raw materials.
 * Stock quantity and stock value are not converted or rewritten.
 */
final class MaterialEffectiveRate
{
    public function __construct(
        private readonly InventoryUnitConversion $units = new InventoryUnitConversion,
    ) {}

    /**
     * Effective Rate per Kg = Stock Value ÷ quantity converted to Kg.
     * Non-weight units stay in the stock unit (value ÷ quantity).
     */
    public function rate(float $stockValue, float $quantity, ?string $unit): ?float
    {
        if ($stockValue <= 0 || $quantity <= 0) {
            return null;
        }

        $qtyForRate = $this->quantityForRate($quantity, $unit);
        if ($qtyForRate <= 0) {
            return null;
        }

        return round($stockValue / $qtyForRate, 2);
    }

    public function format(float $stockValue, float $quantity, ?string $unit): string
    {
        $rate = $this->rate($stockValue, $quantity, $unit);
        if ($rate === null) {
            return '—';
        }

        return '₹'.number_format($rate, 2, '.', ',').'/'.$this->rateUnit($unit);
    }

    public function quantityForRate(float $quantity, ?string $unit): float
    {
        $normalized = $this->normalizedUnit($unit);
        if ($normalized === null) {
            return $quantity;
        }

        if ($this->units->family($normalized) !== InventoryUnitConversion::FAMILY_WEIGHT) {
            return $quantity;
        }

        return round($quantity * $this->units->conversionFactor($normalized, InventoryUnit::Kg->value), 6);
    }

    public function rateUnit(?string $unit): string
    {
        $normalized = $this->normalizedUnit($unit);
        if ($normalized === null) {
            return InventoryUnit::Kg->value;
        }

        if ($this->units->family($normalized) === InventoryUnitConversion::FAMILY_WEIGHT) {
            return InventoryUnit::Kg->value;
        }

        return $normalized;
    }

    private function normalizedUnit(?string $unit): ?string
    {
        $unit = trim((string) $unit);

        return $unit === '' ? null : $this->units->normalize($unit);
    }
}
