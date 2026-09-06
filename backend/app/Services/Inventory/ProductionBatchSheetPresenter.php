<?php

namespace App\Services\Inventory;

use App\Models\ProductionBatch;
use App\Models\ProductionBatchConsumption;

/**
 * Display/print payload for a confirmed production batch.
 * Converts inventory-unit consumption back to the confirmation (formulation) UOM.
 * Does not change stored stock, BOM, or costing figures.
 */
final class ProductionBatchSheetPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function for(ProductionBatch $batch, bool $showCosts): array
    {
        $batch->loadMissing(['product', 'semiFinished', 'bom', 'supervisor', 'consumptions']);

        $productName = $batch->product?->displayLabel()
            ?? trim(($batch->semiFinished?->material_code ? $batch->semiFinished->material_code.' — ' : '').($batch->semiFinished?->material_name ?? ''))
            ?: '—';

        $productionUnit = (string) ($batch->bom?->batch_unit
            ?: ($batch->product?->production_unit ?: $batch->product?->uom ?: $batch->semiFinished?->unit ?: 'Nos'));

        $finishedPacks = $batch->finished_packs_produced !== null
            ? (float) $batch->finished_packs_produced
            : (float) $batch->actual_output_quantity;

        $conversionCost = (float) $batch->total_conversion_cost;

        return [
            'batch_number' => (string) $batch->batch_number,
            'production_date' => $batch->production_date?->format('d M Y') ?: '—',
            'product_name' => $productName,
            'bom_number' => (string) ($batch->bom?->bom_number ?: '—'),
            'production_quantity' => (float) $batch->actual_output_quantity,
            'production_unit' => $productionUnit,
            'finished_packs' => $finishedPacks,
            'status_label' => $batch->status->label(),
            'materials' => self::materials($batch, $showCosts),
            'show_costs' => $showCosts,
            'material_cost' => (float) $batch->total_material_cost,
            'packaging_cost' => (float) $batch->total_packaging_cost,
            'conversion_cost' => $conversionCost,
            'has_conversion_cost' => $conversionCost > 0.0001,
            'labour_cost' => (float) $batch->labour_cost,
            'labour_rate_per_nos' => $batch->labour_rate_per_nos !== null
                ? (float) $batch->labour_rate_per_nos
                : null,
            'transport_cost' => (float) $batch->transport_cost,
            'other_manufacturing_cost' => (float) $batch->other_manufacturing_cost,
            'total_batch_cost' => (float) $batch->total_batch_cost,
            'cost_per_unit' => (float) $batch->cost_per_unit,
            'cost_per_pack' => (float) $batch->cost_per_pack,
            'remarks' => filled($batch->notes) ? (string) $batch->notes : '—',
            'prepared_by' => (string) ($batch->supervisor?->name ?: '—'),
        ];
    }

    /**
     * @return list<array{
     *   material_name: string,
     *   required_qty: float,
     *   actual_qty: float,
     *   uom: string,
     *   rate: float,
     *   cost: float
     * }>
     */
    public static function materials(ProductionBatch $batch, bool $showCosts): array
    {
        $converter = app(InventoryUnitConversion::class);

        return $batch->consumptions
            ->map(function (ProductionBatchConsumption $consumption) use ($converter, $showCosts): array {
                $formUnit = self::formulationUnit($consumption, $converter);
                $invUnit = self::inventoryUnit($consumption, $converter);
                $required = self::requiredInFormulation($consumption, $converter, $formUnit, $invUnit);
                $actual = self::actualInFormulation($consumption, $converter, $formUnit, $invUnit);
                $rate = self::rateInFormulation((float) $consumption->rate, $formUnit, $invUnit, $converter);

                return [
                    'material_name' => (string) $consumption->material_name,
                    'required_qty' => $required,
                    'actual_qty' => $actual,
                    'uom' => $formUnit !== '' ? $formUnit : $invUnit,
                    'rate' => $showCosts ? $rate : 0.0,
                    'cost' => $showCosts ? (float) $consumption->consumption_value : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private static function formulationUnit(ProductionBatchConsumption $consumption, InventoryUnitConversion $converter): string
    {
        $unit = trim((string) ($consumption->formulation_unit ?: $consumption->unit ?: ''));

        return $unit !== '' ? $converter->normalize($unit) : '';
    }

    private static function inventoryUnit(ProductionBatchConsumption $consumption, InventoryUnitConversion $converter): string
    {
        $unit = trim((string) ($consumption->inventory_unit ?: $consumption->unit ?: ''));

        return $unit !== '' ? $converter->normalize($unit) : '';
    }

    private static function requiredInFormulation(
        ProductionBatchConsumption $consumption,
        InventoryUnitConversion $converter,
        string $formUnit,
        string $invUnit,
    ): float {
        if ($consumption->formulation_quantity !== null) {
            return round((float) $consumption->formulation_quantity, 6);
        }

        return self::toFormulationQty(
            (float) ($consumption->required_quantity ?? $consumption->standard_quantity ?? 0),
            $consumption,
            $converter,
            $formUnit,
            $invUnit,
        );
    }

    private static function actualInFormulation(
        ProductionBatchConsumption $consumption,
        InventoryUnitConversion $converter,
        string $formUnit,
        string $invUnit,
    ): float {
        return self::toFormulationQty(
            (float) $consumption->consumed_quantity,
            $consumption,
            $converter,
            $formUnit,
            $invUnit,
        );
    }

    private static function toFormulationQty(
        float $inventoryQty,
        ProductionBatchConsumption $consumption,
        InventoryUnitConversion $converter,
        string $formUnit,
        string $invUnit,
    ): float {
        if ($formUnit === '' || $invUnit === '' || $formUnit === $invUnit) {
            return round($inventoryQty, 6);
        }

        $ratio = (float) ($consumption->conversion_ratio ?? 0);
        if ($ratio > 0) {
            return round($inventoryQty / $ratio, 6);
        }

        try {
            return (float) $converter->convert($inventoryQty, $invUnit, $formUnit)['quantity'];
        } catch (\Throwable) {
            return round($inventoryQty, 6);
        }
    }

    private static function rateInFormulation(
        float $inventoryRate,
        string $formUnit,
        string $invUnit,
        InventoryUnitConversion $converter,
    ): float {
        if ($formUnit === '' || $invUnit === '' || $formUnit === $invUnit) {
            return round($inventoryRate, 4);
        }

        try {
            if ($converter->areCompatible($formUnit, $invUnit)) {
                return round($inventoryRate * $converter->conversionFactor($formUnit, $invUnit), 4);
            }
        } catch (\Throwable) {
            // Keep inventory rate if conversion is unavailable.
        }

        return round($inventoryRate, 4);
    }
}
