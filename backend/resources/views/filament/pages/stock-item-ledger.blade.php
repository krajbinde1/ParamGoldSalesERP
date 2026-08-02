<x-filament-panels::page>
    <div class="stock-item-ledger-page">
        @if (! $this->shouldShowLedger())
            <div class="stock-item-ledger-empty print:hidden">
                Open an item from <strong>Inventory Reports</strong> using <strong>View Ledger</strong>.
            </div>
        @else
            @php
                $ledger = $this->ledgerResult();
                $h = $ledger->header;
                $t = $ledger->totals;
                $showCosts = $this->canViewCostColumns();
                $fromLabel = \Illuminate\Support\Carbon::parse($h['from'])->format('d-m-Y');
                $blank = '—';
            @endphp

            <div class="stock-item-ledger-print-area">
                <header class="stock-item-ledger-heading">
                    <h2 class="stock-item-ledger-heading__name">{{ $h['item_name'] }}</h2>
                    <p class="stock-item-ledger-heading__title">Stock Ledger</p>
                    <p class="stock-item-ledger-heading__meta">Code : {{ $h['item_code'] }}</p>
                    <p class="stock-item-ledger-heading__meta">Unit : {{ $h['unit'] }}</p>
                    @if (! empty($h['warning']))
                        <p class="stock-item-ledger-warning print:hidden" title="{{ $h['warning'] }}">!</p>
                    @endif
                </header>

                <div class="stock-item-ledger-filters print:hidden">
                    <label class="stock-item-ledger-filters__field">
                        <span>From Date</span>
                        <input type="date" wire:model="data.from" />
                    </label>
                    <label class="stock-item-ledger-filters__field">
                        <span>To Date</span>
                        <input type="date" wire:model="data.to" />
                    </label>
                    <button type="button" class="stock-item-ledger-filters__apply" wire:click="applyFilters">
                        Apply Filter
                    </button>
                </div>

                <div class="stock-item-ledger-table-wrap">
                    <table class="stock-item-ledger-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Particulars</th>
                                <th>Voucher / Ref. No.</th>
                                <th class="num">Inward Quantity</th>
                                @if ($showCosts)
                                    <th class="num">Inward Value</th>
                                @endif
                                <th class="num">Outward Quantity</th>
                                @if ($showCosts)
                                    <th class="num">Outward Value</th>
                                @endif
                                <th class="num">Closing Quantity</th>
                                @if ($showCosts)
                                    <th class="num">Average Purchase Rate</th>
                                    <th class="num">Closing Value</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="stock-ledger-row-opening">
                                <td class="nowrap">{{ $fromLabel }}</td>
                                <td>Opening Balance</td>
                                <td>{{ $blank }}</td>
                                <td class="num">{{ $blank }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ $blank }}</td>
                                @endif
                                <td class="num">{{ $blank }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ $blank }}</td>
                                @endif
                                <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatQty($h['opening_qty']) }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatRate($h['opening_rate']) }}</td>
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatMoney($h['opening_value']) }}</td>
                                @endif
                            </tr>

                            @forelse ($ledger->rows as $row)
                                <tr>
                                    <td class="nowrap">{{ $row['date'] }}</td>
                                    <td>{{ $row['particulars'] }}</td>
                                    <td class="nowrap">
                                        @if (! empty($row['voucher_url']) && filled($row['voucher_no']))
                                            <a href="{{ $row['voucher_url'] }}" class="stock-item-ledger-ref" target="_blank" rel="noopener">
                                                {{ $row['voucher_no'] }}
                                            </a>
                                        @else
                                            {{ filled($row['voucher_no']) ? $row['voucher_no'] : $blank }}
                                        @endif
                                    </td>
                                    <td class="num">{{ $row['inward_qty'] !== null ? \App\Filament\Pages\StockItemLedger::formatQty($row['inward_qty']) : $blank }}</td>
                                    @if ($showCosts)
                                        <td class="num">{{ $row['inward_value'] !== null ? \App\Filament\Pages\StockItemLedger::formatMoney($row['inward_value']) : $blank }}</td>
                                    @endif
                                    <td class="num">{{ $row['outward_qty'] !== null ? \App\Filament\Pages\StockItemLedger::formatQty($row['outward_qty']) : $blank }}</td>
                                    @if ($showCosts)
                                        <td class="num">{{ $row['outward_value'] !== null ? \App\Filament\Pages\StockItemLedger::formatMoney($row['outward_value']) : $blank }}</td>
                                    @endif
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatQty($row['closing_qty']) }}</td>
                                    @if ($showCosts)
                                        <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatRate($row['closing_rate']) }}</td>
                                        <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatMoney($row['closing_value']) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $showCosts ? 10 : 6 }}" class="stock-item-ledger-empty-row">
                                        No transactions in the selected period.
                                    </td>
                                </tr>
                            @endforelse

                            <tr class="stock-ledger-row-closing">
                                <td></td>
                                <td>Closing Balance</td>
                                <td></td>
                                <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatQty($t['total_inward_qty']) }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatMoney($t['total_inward_value']) }}</td>
                                @endif
                                <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatQty($t['total_outward_qty']) }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatMoney($t['total_outward_value']) }}</td>
                                @endif
                                <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatQty($t['closing_qty']) }}</td>
                                @if ($showCosts)
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatRate($t['closing_rate']) }}</td>
                                    <td class="num">{{ \App\Filament\Pages\StockItemLedger::formatMoney($t['closing_value']) }}</td>
                                @endif
                            </tr>
                        </tbody>
                    </table>
                </div>

                @if ($ledger->totalTransactionCount > $ledger->perPage)
                    <div class="stock-item-ledger-pager print:hidden">
                        <p>
                            Page {{ $ledger->page }} of {{ $ledger->lastPage() }}
                            ({{ $ledger->totalTransactionCount }} transactions)
                        </p>
                        <div class="stock-item-ledger-pager__actions">
                            <x-filament::button color="gray" size="sm" wire:click="previousPage" :disabled="$ledger->page <= 1">
                                Previous
                            </x-filament::button>
                            <x-filament::button color="gray" size="sm" wire:click="nextPage" :disabled="$ledger->page >= $ledger->lastPage()">
                                Next
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <style>
        .stock-item-ledger-page {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .stock-item-ledger-empty {
            border: 1px dashed #d1d5db;
            background: #f9fafb;
            padding: 1.5rem;
            text-align: center;
            font-size: 0.875rem;
            color: #4b5563;
        }

        .stock-item-ledger-heading {
            text-align: center;
            line-height: 1.3;
            margin: 0;
        }

        .stock-item-ledger-heading__name {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }

        .stock-item-ledger-heading__title {
            margin: 0.05rem 0 0;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #1f2937;
        }

        .stock-item-ledger-heading__meta {
            margin: 0.05rem 0 0;
            font-size: 0.75rem;
            color: #374151;
        }

        .stock-item-ledger-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 0.5rem 0.65rem;
            margin: 0.15rem 0 0.1rem;
        }

        .stock-item-ledger-filters__field {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            min-width: 0;
        }

        .stock-item-ledger-filters__field span {
            font-size: 0.6875rem;
            font-weight: 600;
            color: #4b5563;
            line-height: 1.1;
        }

        .stock-item-ledger-filters__field input[type="date"] {
            width: 9.5rem;
            max-width: 100%;
            height: 2rem;
            border: 1px solid #9ca3af;
            border-radius: 0.25rem;
            padding: 0.15rem 0.4rem;
            font-size: 0.8125rem;
            background: #fff;
            color: #111827;
        }

        .stock-item-ledger-filters__apply {
            height: 2rem;
            border: 1px solid #1d4ed8;
            border-radius: 0.25rem;
            background: #2563eb;
            color: #fff;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0 0.75rem;
            white-space: nowrap;
            cursor: pointer;
        }

        .stock-item-ledger-filters__apply:hover {
            background: #1d4ed8;
        }

        @media (max-width: 640px) {
            .stock-item-ledger-filters {
                flex-direction: column;
                align-items: stretch;
            }

            .stock-item-ledger-filters__field input[type="date"],
            .stock-item-ledger-filters__apply {
                width: 100%;
            }
        }

        @media (min-width: 641px) and (max-width: 900px) {
            .stock-item-ledger-filters__field input[type="date"] {
                width: 8.75rem;
            }
        }

        .stock-item-ledger-warning {
            display: inline-block;
            margin: 0.25rem auto 0;
            width: 1rem;
            height: 1rem;
            border-radius: 9999px;
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #92400e;
            font-size: 0.65rem;
            font-weight: 700;
            line-height: 1rem;
            text-align: center;
            cursor: help;
        }

        .stock-item-ledger-table-wrap {
            overflow-x: auto;
            background: #fff;
            border: 1px solid #111;
        }

        .stock-item-ledger-table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
            background: #fff;
            font-size: 0.75rem;
            color: #111;
        }

        .stock-item-ledger-table th,
        .stock-item-ledger-table td {
            border: 1px solid #333;
            padding: 0.2rem 0.35rem;
            vertical-align: middle;
            line-height: 1.25;
        }

        .stock-item-ledger-table thead th {
            background: #f3f4f6;
            font-weight: 700;
            text-align: left;
            white-space: nowrap;
        }

        .stock-item-ledger-table th.num,
        .stock-item-ledger-table td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .stock-item-ledger-table .nowrap {
            white-space: nowrap;
        }

        .stock-ledger-row-opening {
            background: #f8fafc;
            font-weight: 600;
        }

        .stock-ledger-row-closing {
            background: #fff;
            font-weight: 700;
            border-top: 2px solid #111;
        }

        .stock-ledger-row-closing td {
            border-top-width: 2px;
        }

        .stock-item-ledger-ref {
            color: #1d4ed8;
            text-decoration: underline;
            text-decoration-style: dotted;
        }

        .stock-item-ledger-empty-row {
            text-align: center;
            color: #6b7280;
            padding: 0.75rem !important;
        }

        .stock-item-ledger-pager {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.8125rem;
            color: #4b5563;
        }

        .stock-item-ledger-pager__actions {
            display: flex;
            gap: 0.5rem;
        }

        .dark .stock-item-ledger-heading__name,
        .dark .stock-item-ledger-heading__title {
            color: #f9fafb;
        }

        .dark .stock-item-ledger-heading__meta {
            color: #d1d5db;
        }

        .dark .stock-item-ledger-filters__field span {
            color: #d1d5db;
        }

        .dark .stock-item-ledger-filters__field input[type="date"] {
            background: #111827;
            border-color: #6b7280;
            color: #e5e7eb;
        }

        .dark .stock-item-ledger-table-wrap,
        .dark .stock-item-ledger-table {
            background: #111827;
            color: #e5e7eb;
            border-color: #9ca3af;
        }

        .dark .stock-item-ledger-table th,
        .dark .stock-item-ledger-table td {
            border-color: #6b7280;
        }

        .dark .stock-item-ledger-table thead th {
            background: #1f2937;
        }

        .dark .stock-ledger-row-opening {
            background: #1f2937;
        }

        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }

            .fi-topbar,
            .fi-sidebar,
            .fi-header-actions,
            .print\:hidden {
                display: none !important;
            }

            .stock-item-ledger-page {
                padding: 0 !important;
            }

            .stock-item-ledger-table-wrap {
                overflow: visible !important;
            }

            .stock-item-ledger-table {
                min-width: 0 !important;
                font-size: 10px !important;
            }
        }
    </style>
</x-filament-panels::page>
