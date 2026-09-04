@php
    use App\Enums\BomItemType;
    use App\Models\Bom;
    use App\Models\BomItem;

    /** @var Bom $record */
    $record->loadMissing(['items.rawMaterial', 'items.packagingMaterial', 'items.semiFinished']);

    $formatQty = static function ($value, int $decimals = 4): string {
        $formatted = rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    };
@endphp

<div class="pg-bom-card">
    <div class="pg-bom-card__head">
        <h2 class="pg-bom-card__title">BOM Items</h2>
        <p class="pg-bom-card__count">{{ $record->items->count() }} {{ \Illuminate\Support\Str::plural('item', $record->items->count()) }}</p>
    </div>

    <div class="pg-bom-items__scroll">
        <table class="pg-bom-items__table">
            <thead>
                <tr>
                    <th>Item Type</th>
                    <th>Material</th>
                    <th class="num">Required Qty</th>
                    <th>Unit</th>
                    <th>Inventory Equivalent</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($record->items as $item)
                    @php
                        /** @var BomItem $item */
                        $type = $item->item_type instanceof BomItemType
                            ? $item->item_type
                            : BomItemType::tryFrom((string) $item->item_type);
                        $badgeClass = match ($type) {
                            BomItemType::RawMaterial => 'pg-bom-type pg-bom-type--raw',
                            BomItemType::SemiFinished => 'pg-bom-type pg-bom-type--bulk',
                            BomItemType::PackagingMaterial => 'pg-bom-type pg-bom-type--pack',
                            default => 'pg-bom-type',
                        };
                        $code = match ($type) {
                            BomItemType::RawMaterial => trim((string) ($item->rawMaterial?->material_code ?? '')),
                            BomItemType::PackagingMaterial => trim((string) ($item->packagingMaterial?->packaging_code ?? '')),
                            BomItemType::SemiFinished => trim((string) ($item->semiFinished?->material_code ?? '')),
                            default => '',
                        };
                        $inventoryQty = $item->inventory_equivalent_quantity;
                        $inventoryUnit = $item->inventory_unit ?: $item->unit;
                        $inventoryLabel = $inventoryQty === null
                            ? '—'
                            : $formatQty($inventoryQty, 3).($inventoryUnit ? ' '.$inventoryUnit : '');
                        $remarks = trim((string) ($item->remarks ?? ''));
                    @endphp
                    <tr>
                        <td>
                            <span class="{{ $badgeClass }}">{{ $type?->label() ?? '—' }}</span>
                        </td>
                        <td>
                            <p class="pg-bom-items__name">{{ $item->materialName() }}</p>
                            @if ($code !== '')
                                <p class="pg-bom-items__code">{{ $code }}</p>
                            @endif
                        </td>
                        <td class="num">{{ $formatQty($item->required_quantity) }}</td>
                        <td>{{ $item->unit ?: '—' }}</td>
                        <td>{{ $inventoryLabel }}</td>
                        <td class="pg-bom-items__remarks">{{ $remarks !== '' ? $remarks : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="pg-bom-items__empty">No BOM items</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
