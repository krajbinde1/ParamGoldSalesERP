<?php

namespace App\Http\Resources\Production;

use App\Models\ProductionBatch;
use App\Models\ProductionBatchConsumption;
use App\Models\User;

final class ProductionBatchPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(ProductionBatch $batch, User $viewer): array
    {
        $data = [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'product_id' => $batch->product_id,
            'product_name' => $batch->product?->product_name
                ?? $batch->semiFinished?->material_name,
            'output_item_name' => $batch->product?->product_name
                ?? $batch->semiFinished?->material_name,
            'output_type' => $batch->output_type instanceof \BackedEnum
                ? $batch->output_type->value
                : (string) ($batch->output_type ?? 'finished_product'),
            'product_code' => $batch->product?->product_code,
            'product_label' => $batch->product?->displayLabel(),
            'bom_id' => $batch->bom_id,
            'bom_version' => $batch->bom_version,
            'production_date' => $batch->production_date?->toDateString(),
            // The UI works with a single Production Quantity concept; it is
            // represented internally by the actual_output_quantity column.
            'production_quantity' => (float) $batch->actual_output_quantity,
            'planned_quantity' => (float) $batch->planned_quantity,
            'actual_output_quantity' => (float) $batch->actual_output_quantity,
            'wastage_quantity' => (float) $batch->wastage_quantity,
            'finished_packs_produced' => $batch->finished_packs_produced !== null ? (float) $batch->finished_packs_produced : null,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'supervisor_id' => $batch->supervisor_id,
            'supervisor_name' => $batch->supervisor?->name,
            'has_material_deviation' => (bool) $batch->has_material_deviation,
            'has_quantity_variance' => (bool) $batch->has_quantity_variance,
            'requires_approval' => (bool) $batch->requires_approval,
            'rejection_reason' => $batch->rejection_reason,
            'notes' => $batch->notes,
            'completed_at' => $batch->completed_at?->toDateTimeString(),
        ];

        if ($viewer->canViewProductionCosts()) {
            $data['total_batch_cost'] = (float) $batch->total_batch_cost;
            $data['cost_per_unit'] = (float) $batch->cost_per_unit;
            $data['cost_per_pack'] = (float) $batch->cost_per_pack;
            $data['cost_per_case'] = $batch->cost_per_case !== null ? (float) $batch->cost_per_case : null;
            $data['total_material_cost'] = (float) $batch->total_material_cost;
            $data['total_packaging_cost'] = (float) $batch->total_packaging_cost;
            $data['total_conversion_cost'] = (float) $batch->total_conversion_cost;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ProductionBatch $batch, User $viewer): array
    {
        $data = self::summary($batch, $viewer);
        $showCosts = $viewer->canViewProductionCosts();

        $data['expiry_date'] = $batch->expiry_date?->toDateString();
        $data['finished_product_ledger_id'] = $batch->finished_product_ledger_id;
        $data['semi_finished_ledger_id'] = $batch->semi_finished_ledger_id;
        $data['labour_cost'] = $showCosts ? (float) $batch->labour_cost : null;
        $data['transport_cost'] = $showCosts ? (float) $batch->transport_cost : null;
        $data['other_manufacturing_cost'] = $showCosts ? (float) $batch->other_manufacturing_cost : null;
        $data['submitted_for_approval_at'] = $batch->submitted_for_approval_at?->toDateTimeString();
        $data['approved_by'] = $batch->approved_by;
        $data['approved_by_name'] = $batch->approvedBy?->name;
        $data['approved_at'] = $batch->approved_at?->toDateTimeString();
        $data['rejected_by'] = $batch->rejected_by;
        $data['rejected_by_name'] = $batch->rejectedBy?->name;
        $data['rejected_at'] = $batch->rejected_at?->toDateTimeString();
        $data['approval_notes'] = $batch->approval_notes;
        $data['started_at'] = $batch->started_at?->toDateTimeString();
        $data['stock_posted'] = $batch->status->value === 'completed';
        $data['consumptions'] = $batch->consumptions->map(
            fn (ProductionBatchConsumption $c): array => self::consumption($c, $showCosts)
        )->values()->all();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function consumption(ProductionBatchConsumption $c, bool $showCosts): array
    {
        return [
            'id' => $c->id,
            'bom_item_id' => $c->bom_item_id,
            'item_type' => $c->item_type->value,
            'raw_material_id' => $c->raw_material_id,
            'packaging_material_id' => $c->packaging_material_id,
            'material_name' => $c->material_name,
            'original_material_name' => $c->original_material_name,
            'unit' => $c->unit,
            'standard_quantity' => (float) $c->standard_quantity,
            'required_quantity' => (float) $c->required_quantity,
            'consumed_quantity' => (float) $c->consumed_quantity,
            'variance_quantity' => (float) $c->variance_quantity,
            'variance_percentage' => (float) $c->variance_percentage,
            'conversion_ratio' => (float) $c->conversion_ratio,
            'stock_before' => (float) $c->stock_before,
            'stock_after' => (float) $c->stock_after,
            'is_optional' => (bool) $c->is_optional,
            'is_substituted' => (bool) $c->is_substituted,
            'substitution_reason' => $c->substitution_reason,
            'substitution_remarks' => $c->substitution_remarks,
            'rate' => $showCosts ? (float) $c->rate : null,
            'consumption_value' => $showCosts ? (float) $c->consumption_value : null,
        ];
    }
}
