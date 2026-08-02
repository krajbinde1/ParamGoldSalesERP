<?php

namespace App\Services\Inventory;

use Illuminate\Validation\ValidationException;

/**
 * Shared landed-cost / effective-rate calculations for material inwards.
 *
 * Base Purchase Value = Qty × Purchase Rate
 * Taxable Amount = Base − Discount + Other Taxable Charges (Freight NOT taxable)
 * GST Amount = Taxable Amount × GST %
 * Total (invoice/display) = Taxable Amount + GST Amount  (excludes Freight)
 * Effective Inventory Value / landed_cost = Total + Freight  (stock valuation)
 * Effective Rate = Effective Inventory Value ÷ Quantity
 */
final class MaterialInwardCosting
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function calculateItemAmounts(array $item): array
    {
        $inwardQty = round((float) ($item['inward_quantity']
            ?? $item['accepted_quantity']
            ?? $item['received_quantity']
            ?? 0), 3);

        if ($inwardQty <= 0 || ! is_finite($inwardQty)) {
            throw ValidationException::withMessages([
                'items' => 'Inward quantity must be greater than zero.',
            ]);
        }

        $basicRate = round((float) ($item['basic_rate'] ?? 0), 4);

        if ($basicRate < 0 || ! is_finite($basicRate)) {
            throw ValidationException::withMessages([
                'items' => 'Purchase rate cannot be negative.',
            ]);
        }

        if ($basicRate <= 0) {
            throw ValidationException::withMessages([
                'items' => 'Purchase rate must be greater than zero.',
            ]);
        }

        $discountAmount = round((float) ($item['discount_amount'] ?? 0), 2);
        $freight = round((float) ($item['freight_amount'] ?? 0), 2);
        $other = round((float) ($item['other_charges'] ?? 0), 2);
        $gstPct = round((float) ($item['gst_percentage'] ?? 0), 2);

        foreach ([$discountAmount, $freight, $other, $gstPct] as $amount) {
            if (! is_finite($amount)) {
                throw ValidationException::withMessages([
                    'items' => 'Discount, freight, other charges, and GST must be valid numbers.',
                ]);
            }
        }

        if ($discountAmount < 0 || $freight < 0 || $other < 0 || $gstPct < 0) {
            throw ValidationException::withMessages([
                'items' => 'Discount, freight, other charges, and GST cannot be negative.',
            ]);
        }

        $basicValue = round($inwardQty * $basicRate, 2);

        if ($discountAmount > $basicValue) {
            throw ValidationException::withMessages([
                'items' => 'Discount cannot exceed purchase value (Qty × Purchase Rate).',
            ]);
        }

        // Taxable excludes freight; freight is added after GST into inventory value.
        $taxable = round($basicValue - $discountAmount + $other, 2);

        if ($taxable < 0 || ! is_finite($taxable)) {
            throw ValidationException::withMessages([
                'items' => 'Taxable material value cannot be negative.',
            ]);
        }

        $gstTotal = round($taxable * $gstPct / 100, 2);

        // Display/invoice Total excludes freight; stock uses Effective = Total + Freight.
        $totalAmount = round($taxable + $gstTotal, 2);
        $effectiveInventoryValue = round($totalAmount + $freight, 2);
        $effectiveRate = round($effectiveInventoryValue / $inwardQty, 4);

        if (! is_finite($gstTotal) || ! is_finite($totalAmount) || ! is_finite($effectiveInventoryValue) || ! is_finite($effectiveRate)) {
            throw ValidationException::withMessages([
                'items' => 'Calculated inventory amounts must be finite numbers.',
            ]);
        }

        return [
            ...$item,
            'inward_quantity' => $inwardQty,
            'received_quantity' => $inwardQty,
            'accepted_quantity' => $inwardQty,
            'rejected_quantity' => 0,
            'free_quantity' => 0,
            'basic_rate' => $basicRate,
            'discount_percentage' => 0,
            'discount_amount' => $discountAmount,
            'freight_amount' => $freight,
            'loading_unloading_amount' => 0,
            'other_charges' => $other,
            'taxable_amount' => $taxable,
            'gst_percentage' => $gstPct,
            'cgst_amount' => 0.0,
            'sgst_amount' => 0.0,
            'igst_amount' => $gstTotal,
            'landed_cost' => $effectiveInventoryValue,
            'effective_unit_rate' => $effectiveRate,
            'total_amount' => $totalAmount,
        ];
    }

    public function calculateWeightedAverageRate(
        float $existingStock,
        float $existingAverageRate,
        float $acceptedQuantity,
        float $effectiveUnitRate,
    ): float {
        $newStock = $existingStock + $acceptedQuantity;
        if ($newStock <= 0) {
            return 0.0;
        }

        if ($existingStock <= 0) {
            return round($effectiveUnitRate, 4);
        }

        return round(
            (($existingStock * $existingAverageRate) + ($acceptedQuantity * $effectiveUnitRate)) / $newStock,
            4,
        );
    }
}
