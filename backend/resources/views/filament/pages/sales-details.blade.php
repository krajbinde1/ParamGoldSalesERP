<x-filament-panels::page>
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <p class="pg-section-sub">{{ $this->periodLabel() }}</p>
                </div>
                <div class="pg-seg" role="group" aria-label="Sales period filters">
                    @foreach ($this->periodFilters() as $key => $label)
                        <button
                            type="button"
                            wire:click="setPeriod('{{ $key }}')"
                            @class(['pg-seg__btn', 'pg-seg__btn--active' => $this->isActivePeriod($key)])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->showCustomPeriod())
                <div class="manager-custom-period-row">
                    <div class="manager-custom-period-field">
                        <label for="sales-details-from-date" class="manager-custom-period-label">From Date</label>
                        <input id="sales-details-from-date" type="date" wire:model="customFromDate" class="manager-custom-period-input">
                        @error('customFromDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="manager-custom-period-field">
                        <label for="sales-details-to-date" class="manager-custom-period-label">To Date</label>
                        <input id="sales-details-to-date" type="date" wire:model="customToDate" class="manager-custom-period-input">
                        @error('customToDate')<p class="manager-custom-period-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="manager-custom-period-actions">
                        <button type="button" wire:click="applyCustomPeriod" class="manager-period-action-btn manager-period-action-btn--apply">Apply</button>
                        <button type="button" wire:click="resetCustomPeriod" class="manager-period-action-btn manager-period-action-btn--clear">Reset</button>
                    </div>
                </div>
            @endif

            @php $details = $this->details(); @endphp

            @include('filament.partials.paramgold-summary-cards', [
                'cards' => [
                    [
                        'label' => 'Total Sales Amount',
                        'value' => $this->formatMoney((float) $details['total_sales']),
                        'tone' => 'primary',
                    ],
                    [
                        'label' => 'Total Invoices',
                        'value' => (string) $details['total_invoices'],
                        'tone' => 'info',
                    ],
                    [
                        'label' => 'Total Parties',
                        'value' => (string) $details['total_parties'],
                        'tone' => 'success',
                    ],
                ],
            ])

            <div class="pg-sales-details-table-wrap">
                @if ($details['parties'] === [])
                    <p class="pg-empty">No sales found for the selected period.</p>
                @else
                    <table class="pg-team-detail__table pg-sales-details-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Party / Dealer Name</th>
                                <th>Sales Employee</th>
                                <th>Invoice / Bill No.</th>
                                <th class="pg-team-detail__num">Sales Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($details['parties'] as $party)
                                @php
                                    $dealerId = $party['dealer_id'] ?? null;
                                    $expanded = $this->isPartyExpanded($dealerId);
                                    $canExpand = (int) $party['invoice_count'] > 1;
                                @endphp
                                <tr
                                    wire:key="sales-party-{{ $this->partyKey($dealerId) }}"
                                    @class(['pg-sales-party-row', 'pg-sales-party-row--open' => $expanded, 'pg-sales-party-row--clickable' => $canExpand])
                                >
                                    <td>{{ $this->formatDate($party['date_label']) }}</td>
                                    <td>
                                        @if ($canExpand)
                                            <button
                                                type="button"
                                                class="pg-sales-party-toggle"
                                                wire:click="toggleParty({{ $dealerId === null ? 'null' : (int) $dealerId }})"
                                                aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                            >
                                                <span aria-hidden="true">{{ $expanded ? '▾' : '▸' }}</span>
                                                {{ $party['dealer_name'] }}
                                            </button>
                                        @else
                                            {{ $party['dealer_name'] }}
                                        @endif
                                    </td>
                                    <td>{{ $party['employee_label'] }}</td>
                                    <td>
                                        @if (! $canExpand && isset($party['invoices'][0]['id']))
                                            <a href="{{ $this->orderUrl((int) $party['invoices'][0]['id']) }}">{{ $party['invoice_label'] }}</a>
                                        @else
                                            {{ $party['invoice_label'] }}
                                        @endif
                                    </td>
                                    <td class="pg-team-detail__num">{{ $this->formatMoney((float) $party['sales_amount']) }}</td>
                                </tr>
                                @if ($expanded && $canExpand)
                                    <tr class="pg-sales-invoice-row" wire:key="sales-party-invoices-{{ $this->partyKey($dealerId) }}">
                                        <td colspan="5">
                                            <table class="pg-team-detail__table pg-sales-invoice-table">
                                                <thead>
                                                    <tr>
                                                        <th>Date</th>
                                                        <th>Party / Dealer Name</th>
                                                        <th>Sales Employee</th>
                                                        <th>Invoice / Bill No.</th>
                                                        <th class="pg-team-detail__num">Sales Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($party['invoices'] as $invoice)
                                                        <tr>
                                                            <td>{{ $this->formatDate($invoice['date'] ?? null) }}</td>
                                                            <td>{{ $invoice['dealer_name'] }}</td>
                                                            <td>{{ $invoice['employee_name'] }}</td>
                                                            <td>
                                                                <a href="{{ $this->orderUrl((int) $invoice['id']) }}">{{ $invoice['invoice_no'] }}</a>
                                                            </td>
                                                            <td class="pg-team-detail__num">{{ $this->formatMoney((float) $invoice['sales_amount']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4">Total Sales Amount</th>
                                <td class="pg-team-detail__num">{{ $this->formatMoney((float) $details['total_sales']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
