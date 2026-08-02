@php
    use Illuminate\Support\Carbon;

    $money = static fn (mixed $v, int $d = 2): string => '₹'.number_format((float) $v, $d);
    $dateLabel = filled($productionDate ?? null) ? Carbon::parse($productionDate)->format('d M Y') : '—';
    $qtyLabel = number_format((float) ($productionQuantity ?? 0), 3).(filled($productionUnit ?? null) ? ' '.$productionUnit : '');
    $materialColCount = $showCosts ? 7 : 5;
@endphp

{{-- Scoped ERP confirmation styles (modal window class set from CreateProductionEntry) --}}
<style>
    .erp-production-confirm-modal .fi-modal-footer-actions {
        display: grid;
        grid-template-columns: auto 1fr auto auto;
        width: 100%;
        align-items: center;
        column-gap: 0.75rem;
    }

    .erp-production-confirm-modal .fi-modal-footer-actions > *:nth-child(2) {
        justify-self: center;
    }

    .erp-production-review .erp-mat-table {
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }

    .erp-production-review .erp-mat-table th,
    .erp-production-review .erp-mat-table td {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .erp-production-review .erp-mat-table .fi-ta-header-cell {
        white-space: nowrap;
    }

    .erp-production-review .erp-cost-table {
        table-layout: fixed;
        width: 100%;
        max-width: 36rem;
    }

    .erp-production-review .erp-cost-table col.cost-head {
        width: 62%;
    }

    .erp-production-review .erp-cost-table col.cost-amount {
        width: 38%;
    }

    .erp-production-review .erp-summary-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.35rem 1.5rem;
    }

    @media (min-width: 640px) {
        .erp-production-review .erp-summary-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    .erp-production-review .erp-summary-row {
        display: grid;
        grid-template-columns: 9.5rem minmax(0, 1fr);
        align-items: baseline;
        column-gap: 0.5rem;
        line-height: 1.35;
    }

    .erp-production-review .erp-summary-label {
        color: rgb(107 114 128);
        font-size: 0.8125rem;
        font-weight: 500;
        white-space: nowrap;
    }

    .erp-production-review .erp-summary-label::after {
        content: ' :';
    }

    .erp-production-review .erp-summary-value {
        color: rgb(3 7 18);
        font-size: 0.875rem;
        font-weight: 600;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .dark .erp-production-review .erp-summary-label {
        color: rgb(156 163 175);
    }

    .dark .erp-production-review .erp-summary-value {
        color: rgb(255 255 255);
    }
</style>

<div class="erp-production-review space-y-6">
    {{-- Warnings --}}
    @if ($hasMandatoryShortage)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">
                Production cannot continue
            </x-slot>
            <x-slot name="description">
                The following materials have insufficient stock.
            </x-slot>

            <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-white/5 dark:ring-white/10">
                <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10">
                    <table class="fi-ta-table w-full table-fixed divide-y divide-gray-200 dark:divide-white/10">
                        <thead class="divide-y divide-gray-200 dark:divide-white/5">
                            <tr class="bg-danger-50 dark:bg-danger-500/10">
                                <th class="fi-ta-header-cell px-4 py-2 text-start text-sm font-semibold text-danger-800 dark:text-danger-200">Material</th>
                                <th class="fi-ta-header-cell px-4 py-2 text-end text-sm font-semibold text-danger-800 dark:text-danger-200">Required</th>
                                <th class="fi-ta-header-cell px-4 py-2 text-end text-sm font-semibold text-danger-800 dark:text-danger-200">Available</th>
                                <th class="fi-ta-header-cell px-4 py-2 text-end text-sm font-semibold text-danger-800 dark:text-danger-200">Shortage</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                            @foreach ($shortageRows as $i => $row)
                                <tr @class(['fi-ta-row', 'bg-gray-50 dark:bg-white/5' => $i % 2 === 1])>
                                    <td class="fi-ta-cell px-4 py-2 text-sm font-medium text-gray-950 dark:text-white">{{ $row['material_name'] }}</td>
                                    <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums">{{ $row['required_label'] }}</td>
                                    <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums">{{ $row['available_label'] }}</td>
                                    <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums font-semibold text-danger-600 dark:text-danger-400">{{ $row['shortage_label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
            <x-slot name="heading">
                All required materials are available
            </x-slot>
            <x-slot name="description">
                Stock levels are sufficient for the selected production quantity.
            </x-slot>
        </x-filament::section>
    @endif

    {{-- Production Summary --}}
    <x-filament::section>
        <x-slot name="heading">
            Production Summary
        </x-slot>

        <div class="erp-summary-grid">
            @foreach ([
                ['label' => 'Product', 'value' => $productLabel ?: '—'],
                ['label' => 'BOM', 'value' => $activeBomLabel ?: '—'],
                ['label' => 'Production Date', 'value' => $dateLabel],
                ['label' => 'Production Quantity', 'value' => $qtyLabel],
            ] as $row)
                <div class="erp-summary-row">
                    <span class="erp-summary-label">{{ $row['label'] }}</span>
                    <span class="erp-summary-value">{{ $row['value'] }}</span>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Material Requirements: Filament table layout --}}
    <x-filament::section icon="heroicon-o-table-cells" icon-color="gray">
        <x-slot name="heading">
            Material Requirements
        </x-slot>

        <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-white/5 dark:ring-white/10">
            <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10">
                <div class="max-h-[28rem] overflow-auto">
                    <table class="fi-ta-table erp-mat-table divide-y divide-gray-200 dark:divide-white/10">
                        @if ($showCosts)
                            <colgroup>
                                <col style="width: 22%">
                                <col style="width: 12%">
                                <col style="width: 12%">
                                <col style="width: 14%">
                                <col style="width: 14%">
                                <col style="width: 14%">
                                <col style="width: 12%">
                            </colgroup>
                        @else
                            <colgroup>
                                <col style="width: 28%">
                                <col style="width: 18%">
                                <col style="width: 18%">
                                <col style="width: 20%">
                                <col style="width: 16%">
                            </colgroup>
                        @endif
                        <thead class="divide-y divide-gray-200 dark:divide-white/5">
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                    Material
                                </th>
                                <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                    Required Qty
                                </th>
                                <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                    Available Stock
                                </th>
                                <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                    Balance After Production
                                </th>
                                @if ($showCosts)
                                    <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                        Average Purchase Rate
                                    </th>
                                    <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                        Material Cost
                                    </th>
                                @endif
                                <th class="fi-ta-header-cell sticky top-0 z-10 border-b border-gray-200 bg-gray-50 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @forelse ($materialRows as $index => $row)
                                <tr
                                    @class([
                                        'fi-ta-row transition duration-75 hover:bg-amber-50/70 dark:hover:bg-white/5',
                                        'bg-gray-50/80 dark:bg-white/[0.03]' => $index % 2 === 1,
                                    ])
                                >
                                    <td class="fi-ta-cell whitespace-normal px-4 py-2 text-start text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $row['material_name'] }}
                                    </td>
                                    <td class="fi-ta-cell whitespace-nowrap px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                                        {{ $row['required_label'] }}
                                    </td>
                                    <td class="fi-ta-cell whitespace-nowrap px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                                        {{ $row['available_label'] }}
                                    </td>
                                    <td class="fi-ta-cell whitespace-nowrap px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                                        {{ $row['balance_label'] }}
                                    </td>
                                    @if ($showCosts)
                                        <td class="fi-ta-cell whitespace-nowrap px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">
                                            {{ $row['average_rate_label'] }}
                                        </td>
                                        <td class="fi-ta-cell whitespace-nowrap px-4 py-2 text-end text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ $money($row['material_cost']) }}
                                        </td>
                                    @endif
                                    <td class="fi-ta-cell px-4 py-2 text-end">
                                        <div class="flex justify-end">
                                            <x-filament::badge :color="$row['status_color']">
                                                {{ $row['status_label'] }}
                                            </x-filament::badge>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr class="fi-ta-row">
                                    <td
                                        colspan="{{ $materialColCount }}"
                                        class="fi-ta-cell px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                                    >
                                        No material requirements found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($showCosts && count($materialRows) > 0)
                            <tfoot>
                                <tr class="fi-ta-summary-row border-t-2 border-gray-300 bg-gray-100 dark:border-white/20 dark:bg-white/10">
                                    <td
                                        colspan="5"
                                        class="fi-ta-cell px-4 py-2.5 text-end text-sm font-semibold text-gray-700 dark:text-gray-200"
                                    >
                                        Total Material Cost
                                    </td>
                                    <td class="fi-ta-cell px-4 py-2.5 text-end text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                                        {{ $money($totalMaterialCost ?? 0) }}
                                    </td>
                                    <td class="fi-ta-cell px-4 py-2.5"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </x-filament::section>

    {{-- Manufacturing Cost: compact 2-column summary table (not a vertical card list) --}}
    @if ($showCosts && $costing)
        <x-filament::section icon="heroicon-o-banknotes" icon-color="warning">
            <x-slot name="heading">
                Manufacturing Cost
            </x-slot>

            <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-white/5 dark:ring-white/10">
                <div class="fi-ta-content relative overflow-x-auto">
                    <table class="fi-ta-table erp-cost-table divide-y divide-gray-200 dark:divide-white/10">
                        <colgroup>
                            <col class="cost-head">
                            <col class="cost-amount">
                        </colgroup>
                        <thead>
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <th class="fi-ta-header-cell border-b border-gray-200 px-4 py-2 text-start text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:text-gray-200">
                                    Cost Head
                                </th>
                                <th class="fi-ta-header-cell border-b border-gray-200 px-4 py-2 text-end text-xs font-semibold uppercase tracking-wide text-gray-700 dark:border-white/10 dark:text-gray-200">
                                    Amount
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            <tr class="fi-ta-row hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200">Material Cost</td>
                                <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">{{ $money($costing['total_material_cost'] ?? 0) }}</td>
                            </tr>
                            <tr class="fi-ta-row bg-gray-50/80 hover:bg-gray-50 dark:bg-white/[0.03] dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200">Packaging Cost</td>
                                <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">{{ $money($costing['total_packaging_cost'] ?? 0) }}</td>
                            </tr>
                            <tr class="fi-ta-row hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200">Labour Cost</td>
                                <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">{{ $money($labourCost ?? 0) }}</td>
                            </tr>
                            <tr class="fi-ta-row bg-gray-50/80 hover:bg-gray-50 dark:bg-white/[0.03] dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200">Transport Cost</td>
                                <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">{{ $money($transportCost ?? 0) }}</td>
                            </tr>
                            <tr class="fi-ta-row hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell px-4 py-2 text-start text-sm text-gray-700 dark:text-gray-200">Other Manufacturing Cost</td>
                                <td class="fi-ta-cell px-4 py-2 text-end text-sm tabular-nums text-gray-950 dark:text-white">{{ $money($otherManufacturingCost ?? 0) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/15">
                                <td class="fi-ta-cell px-4 py-2.5 text-start text-sm font-bold text-success-800 dark:text-success-200">
                                    Grand Production Cost
                                </td>
                                <td class="fi-ta-cell px-4 py-2.5 text-end text-base font-bold tabular-nums text-success-800 dark:text-success-200">
                                    {{ $money($costing['total_batch_cost'] ?? 0) }}
                                </td>
                            </tr>
                            <tr class="border-t border-info-200 bg-info-50 dark:border-info-500/30 dark:bg-info-500/15">
                                <td class="fi-ta-cell px-4 py-2.5 text-start text-sm font-semibold text-info-800 dark:text-info-200">
                                    Estimated Cost / Unit
                                </td>
                                <td class="fi-ta-cell px-4 py-2.5 text-end text-base font-bold tabular-nums text-info-800 dark:text-info-200">
                                    {{ $money($costing['cost_per_unit'] ?? 0, 2) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </x-filament::section>
    @endif
</div>
