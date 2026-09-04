@php
    use App\Enums\InventoryUnit;
    use App\Models\Bom;

    /** @var Bom $record */
    $summary = $record->formulaSummary();
    $showCosts = auth()->user()?->canViewProductionCosts() ?? false;
    $money = static fn ($value): string => '₹'.number_format((float) $value, 2);
    $unit = InventoryUnit::tryFromMixed($record->batch_unit);
    $formulaFor = ($unit === null || $unit->usesCountFormulaLabel())
        ? 'Quantity'
        : $unit->formulaShortName();
    $formulaQuantity = $record->formulaQuantityLabel();
    $totalItems = (int) ($summary['total_items'] ?? 0);
    $itemHint = collect([
        ((int) ($summary['raw_material_items'] ?? 0)).' raw',
        ((int) ($summary['semi_finished_items'] ?? 0)).' bulk',
        ((int) ($summary['packaging_material_items'] ?? 0)).' packing',
    ])->implode(' · ');
    $costPerUnit = $summary['estimated_cost_per_finished_unit'] ?? null;
@endphp

<div class="pg-bom-card">
    <div class="pg-bom-card__head">
        <h2 class="pg-bom-card__title">BOM Summary</h2>
    </div>

    <div class="pg-bom-summary">
        <div class="pg-bom-summary__grid">
            <div class="pg-bom-kpi">
                <p class="pg-bom-kpi__label">Formula For</p>
                <p class="pg-bom-kpi__value">{{ $formulaFor }}</p>
            </div>
            <div class="pg-bom-kpi">
                <p class="pg-bom-kpi__label">Formula Quantity</p>
                <p class="pg-bom-kpi__value">{{ $formulaQuantity }}</p>
            </div>
            <div class="pg-bom-kpi">
                <p class="pg-bom-kpi__label">Total Items</p>
                <p class="pg-bom-kpi__value">{{ $totalItems }}</p>
                <p class="pg-bom-kpi__hint">{{ $itemHint }}</p>
            </div>
            @if ($showCosts)
                <div class="pg-bom-kpi">
                    <p class="pg-bom-kpi__label">Estimated Raw Material Cost</p>
                    <p class="pg-bom-kpi__value">{{ $money($summary['estimated_raw_material_cost'] ?? 0) }}</p>
                </div>
                <div class="pg-bom-kpi">
                    <p class="pg-bom-kpi__label">Estimated Bulk Material Cost</p>
                    <p class="pg-bom-kpi__value">{{ $money($summary['estimated_semi_finished_cost'] ?? 0) }}</p>
                </div>
                <div class="pg-bom-kpi">
                    <p class="pg-bom-kpi__label">Estimated Packing Material Cost</p>
                    <p class="pg-bom-kpi__value">{{ $money($summary['estimated_packaging_cost'] ?? 0) }}</p>
                </div>
            @endif
        </div>

        @if ($showCosts)
            <div class="pg-bom-summary__emphasis">
                <div class="pg-bom-kpi pg-bom-kpi--emphasis">
                    <p class="pg-bom-kpi__label">Estimated Total BOM Cost</p>
                    <p class="pg-bom-kpi__value">{{ $money($summary['estimated_total_bom_cost'] ?? 0) }}</p>
                </div>
                <div class="pg-bom-kpi pg-bom-kpi--emphasis">
                    <p class="pg-bom-kpi__label">Estimated Cost / Unit</p>
                    <p class="pg-bom-kpi__value">{{ $costPerUnit !== null ? $money($costPerUnit) : '—' }}</p>
                </div>
            </div>
        @endif
    </div>
</div>
