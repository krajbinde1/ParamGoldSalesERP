<?php

namespace App\Services\Inventory;

use App\Enums\InventoryUnit;

/**
 * Allocates header Transport/Freight Cost across purchase lines by taxable value.
 * Remainder is applied to the last line so allocated amounts always equal the header cost.
 */
final class PurchaseFreightAllocator
{
    public function __construct(
        private readonly InventoryUnitConversion $units = new InventoryUnitConversion,
    ) {}

    /**
     * @param  list<float>  $taxableAmounts
     * @return list<float>
     */
    public function allocate(float $transportCost, array $taxableAmounts): array
    {
        $count = count($taxableAmounts);
        if ($count === 0) {
            return [];
        }

        $freight = round(max(0, $transportCost), 2);
        if ($freight <= 0) {
            return array_fill(0, $count, 0.0);
        }

        $totalTaxable = round(array_sum($taxableAmounts), 2);
        if ($totalTaxable <= 0) {
            $base = round($freight / $count, 2);
            $allocated = array_fill(0, $count, $base);
            $allocated[$count - 1] = round($freight - ($base * ($count - 1)), 2);

            return $allocated;
        }

        $allocated = [];
        $running = 0.0;

        foreach ($taxableAmounts as $index => $taxable) {
            if ($index === $count - 1) {
                $allocated[] = round($freight - $running, 2);

                continue;
            }

            $share = round($freight * ((float) $taxable / $totalTaxable), 2);
            $allocated[] = $share;
            $running = round($running + $share, 2);
        }

        return $allocated;
    }

    public function landedCost(float $taxableAmount, float $allocatedTransportCost): float
    {
        return round(max(0, $taxableAmount) + max(0, $allocatedTransportCost), 2);
    }

    public function effectiveLandedRate(float $quantity, float $taxableAmount, float $allocatedTransportCost): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return round($this->landedCost($taxableAmount, $allocatedTransportCost) / $quantity, 4);
    }

    /**
     * Convert a purchase-line quantity into Ton. Non-weight units return 0.
     */
    public function quantityInTons(float $quantity, ?string $unit): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $raw = trim((string) $unit);
        if ($raw === '') {
            return 0.0;
        }

        $normalized = $this->units->normalize($raw);
        if (! $this->units->areCompatible($normalized, InventoryUnit::Ton->value)) {
            return 0.0;
        }

        return round($quantity * $this->units->conversionFactor($normalized, InventoryUnit::Ton->value), 6);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function totalQuantityInTons(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += $this->quantityInTons(
                (float) ($item['quantity'] ?? 0),
                isset($item['unit']) ? (string) $item['unit'] : null,
            );
        }

        return round($total, 6);
    }

    /**
     * Total Freight Cost = Freight Rate Per Ton × Total Purchase Quantity in Ton.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function totalFreightFromPerTonRate(float $ratePerTon, array $items): float
    {
        $rate = max(0, $ratePerTon);
        $tons = $this->totalQuantityInTons($items);

        return round($rate * $tons, 2);
    }
}
