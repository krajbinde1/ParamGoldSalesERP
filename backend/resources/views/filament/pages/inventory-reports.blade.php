<x-filament-panels::page>
    <div class="inventory-reports-page space-y-4">
        <div class="inventory-reports-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-4">
            {{ $this->form }}
        </div>

        @php
            $report = $this->report;
        @endphp

        @if (count($report->summaryCards))
            <div class="paramgold-summary-grid inventory-reports-summary">
                @foreach ($report->summaryCards as $card)
                    @php
                        $cardFilter = (string) ($card['filter'] ?? '');
                        $method = match ($cardFilter) {
                            'total' => 'filterTotalStock',
                            \App\Services\Inventory\InventoryReportService::TYPE_RAW_MATERIAL => 'filterRawMaterialStock',
                            \App\Services\Inventory\InventoryReportService::TYPE_PACKAGING_MATERIAL => 'filterPackagingMaterialStock',
                            \App\Services\Inventory\InventoryReportService::TYPE_FINISHED_PRODUCT => 'filterFinishedProductStock',
                            'low_stock' => 'filterLowStock',
                            'out_of_stock' => 'filterOutOfStock',
                            default => null,
                        };
                        $isClickable = $method !== null;
                        $isActive = $isClickable && $this->isSummaryCardActive($cardFilter);
                    @endphp
                    <button
                        type="button"
                        @if ($isClickable) wire:click="{{ $method }}" @endif
                        @class([
                            'paramgold-summary-card' => true,
                            'paramgold-summary-card--'.($card['tone'] ?? 'primary') => true,
                            'paramgold-summary-card--clickable' => $isClickable,
                            'paramgold-summary-card--active' => $isActive,
                        ])
                    >
                        <p class="paramgold-summary-card__label">{{ $card['label'] }}</p>
                        <p class="paramgold-summary-card__value">{{ $card['value'] }}</p>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="inventory-reports-table-wrap">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
