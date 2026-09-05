<?php

namespace App\Services\Inventory;

/**
 * GST-exclusive weighted average costing for Raw Material and Packing Material.
 *
 * New Average Rate =
 * (Current Stock Qty × Current Average Rate + Purchase Qty × Purchase Rate)
 * ÷ (Current Stock Qty + Purchase Qty)
 *
 * Purchase Rate / Effective Landed Rate is GST-exclusive.
 * Recoverable GST stays on the purchase document and is never folded into
 * average_rate, stock value, or consumption cost.
 * Effective Landed Rate may include allocated Transport/Freight Cost.
 */
final class WeightedAverageCosting
{
    public function newAverageRate(
        float $currentQty,
        float $currentAverageRate,
        float $purchaseQty,
        float $purchaseRate,
    ): float {
        $newQty = $currentQty + $purchaseQty;
        if ($newQty <= 0) {
            return 0.0;
        }

        if ($currentQty <= 0) {
            return round($purchaseRate, 4);
        }

        if ($purchaseQty <= 0) {
            return round($currentAverageRate, 4);
        }

        return round(
            (($currentQty * $currentAverageRate) + ($purchaseQty * $purchaseRate)) / $newQty,
            4,
        );
    }

    public function stockValue(float $quantity, float $averageRate): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        return round($quantity * $averageRate, 2);
    }

    public function formatRate(float $rate, ?string $unit = null): string
    {
        if ($rate <= 0) {
            return '—';
        }

        $formatted = '₹'.number_format($rate, 2, '.', ',');

        return filled($unit) ? $formatted.'/'.$unit : $formatted;
    }
}
