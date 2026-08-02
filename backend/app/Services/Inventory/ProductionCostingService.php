<?php

namespace App\Services\Inventory;

use App\Enums\BomItemType;

final class ProductionCostingService
{
    /**
     * @param  list<array{item_type: string, consumption_value: float|int|string}>  $consumptions
     * @param  array{
     *   labour_cost?: float|int|string,
     *   transport_cost?: float|int|string,
     *   other_manufacturing_cost?: float|int|string
     * }  $expenses
     * @return array{
     *   total_material_cost: float,
     *   total_packaging_cost: float,
     *   total_conversion_cost: float,
     *   total_batch_cost: float,
     *   cost_per_unit: float,
     *   cost_per_pack: float,
     *   cost_per_case: ?float,
     *   finished_packs_produced: float,
     *   material_cost_per_unit: float,
     *   packaging_cost_per_unit: float,
     *   conversion_cost_per_unit: float
     * }
     */
    public function calculate(
        array $consumptions,
        array $expenses,
        float $productionQuantity,
    ): array {
        $materialCost = 0.0;
        $packagingCost = 0.0;

        foreach ($consumptions as $row) {
            $value = round((float) ($row['consumption_value'] ?? 0), 2);
            $type = $row['item_type'] ?? null;

            if (
                $type === BomItemType::RawMaterial->value
                || $type === BomItemType::RawMaterial
                || $type === BomItemType::SemiFinished->value
                || $type === BomItemType::SemiFinished
            ) {
                // SF consumed at its average production cost (no double-count of original RM).
                $materialCost += $value;
            } elseif ($type === BomItemType::PackagingMaterial->value || $type === BomItemType::PackagingMaterial) {
                $packagingCost += $value;
            }
        }

        // Manufacturing expenses are limited to Labour, Transport, and Other
        // Manufacturing Cost. Electricity/Machine/Processing are no longer
        // collected and never contribute to the conversion cost.
        $labour = round((float) ($expenses['labour_cost'] ?? 0), 2);
        $transport = round((float) ($expenses['transport_cost'] ?? 0), 2);
        $other = round((float) ($expenses['other_manufacturing_cost'] ?? 0), 2);

        $conversion = round($labour + $transport + $other, 2);
        $materialCost = round($materialCost, 2);
        $packagingCost = round($packagingCost, 2);
        $total = round($materialCost + $packagingCost + $conversion, 2);

        $divisor = max($productionQuantity, 0.0001);
        $costPerUnit = round($total / $divisor, 4);

        return [
            'total_material_cost' => $materialCost,
            'total_packaging_cost' => $packagingCost,
            'total_conversion_cost' => $conversion,
            'total_batch_cost' => $total,
            'cost_per_unit' => $costPerUnit,
            'cost_per_pack' => $costPerUnit,
            'cost_per_case' => null,
            'finished_packs_produced' => round($productionQuantity, 3),
            'material_cost_per_unit' => round($materialCost / $divisor, 4),
            'packaging_cost_per_unit' => round($packagingCost / $divisor, 4),
            'conversion_cost_per_unit' => round($conversion / $divisor, 4),
        ];
    }
}
