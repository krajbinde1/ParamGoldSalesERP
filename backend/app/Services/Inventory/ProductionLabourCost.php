<?php

namespace App\Services\Inventory;

/**
 * Total Labour Cost = Production Quantity (Nos) × Labour Rate Per Nos.
 * Existing costing still consumes the computed labour_cost total.
 */
final class ProductionLabourCost
{
    /**
     * When labour_rate_per_nos is present, overwrite labour_cost from qty × rate.
     * Callers that only send labour_cost (legacy) are left unchanged.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function apply(array $input): array
    {
        if (! array_key_exists('labour_rate_per_nos', $input)
            || $input['labour_rate_per_nos'] === null
            || $input['labour_rate_per_nos'] === '') {
            return $input;
        }

        $qty = (float) ($input['planned_quantity']
            ?? $input['actual_output_quantity']
            ?? $input['production_quantity']
            ?? 0);
        $rate = round(max(0.0, (float) $input['labour_rate_per_nos']), 4);
        $input['labour_rate_per_nos'] = $rate;
        $input['labour_cost'] = self::total($qty, $rate);

        return $input;
    }

    public static function total(float $quantity, float $ratePerNos): float
    {
        return round(max(0.0, $quantity) * max(0.0, $ratePerNos), 2);
    }
}
