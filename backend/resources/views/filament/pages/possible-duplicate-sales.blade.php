<x-filament-panels::page>
    <div class="pg-admin-dash space-y-4">
        <x-filament::section>
            <div class="space-y-4">
                <div>
                    <p class="pg-section-sub">
                        Read-only match of ERP Sales Debits against Tally Import Sales Debits.
                        Ledger rows are not deleted or changed from this page.
                        Collections and Receipts are not included.
                    </p>
                </div>

                <div class="manager-custom-period-row">
                    <div class="manager-custom-period-field" style="min-width: 22rem;">
                        <label for="duplicate-sales-dealer" class="manager-custom-period-label">Dealer</label>
                        <select
                            id="duplicate-sales-dealer"
                            wire:model.live="dealerId"
                            class="manager-custom-period-input"
                        >
                            <option value="">All dealers</option>
                            @foreach ($this->dealerOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @php $report = $this->report(); @endphp

                @include('filament.partials.paramgold-summary-cards', [
                    'cards' => array_values(array_filter([
                        [
                            'label' => 'Possible duplicate pairs',
                            'value' => (string) $report['duplicate_count'],
                            'tone' => 'warning',
                        ],
                        [
                            'label' => 'Duplicate amount',
                            'value' => $this->formatMoney($report['duplicate_amount']),
                            'tone' => 'warning',
                        ],
                        $report['current_debit_total'] !== null ? [
                            'label' => 'Current Debit total',
                            'value' => $this->formatMoney($report['current_debit_total']),
                            'tone' => 'info',
                        ] : null,
                        $report['correct_debit_total'] !== null ? [
                            'label' => 'Correct Debit total',
                            'value' => $this->formatMoney($report['correct_debit_total']),
                            'tone' => 'success',
                        ] : null,
                        $report['current_outstanding_signed'] !== null ? [
                            'label' => 'Current outstanding',
                            'value' => $this->formatOutstanding($report['current_outstanding_signed']),
                            'tone' => 'info',
                        ] : null,
                        $report['correct_outstanding_signed'] !== null ? [
                            'label' => 'Correct outstanding',
                            'value' => $this->formatOutstanding($report['correct_outstanding_signed']),
                            'tone' => 'success',
                            'meta' => filled($report['current_opening_label'])
                                ? 'After removing duplicate Debit effect. Opening '.$report['current_opening_label'].' is unchanged.'
                                : null,
                        ] : null,
                    ])),
                ])

                <div class="pg-sales-details-table-wrap">
                    @if ($report['pairs'] === [])
                        <p class="pg-empty">No definite ERP Sales + Tally Sales duplicate Debits found{{ $this->selectedDealer() ? ' for this dealer' : '' }}.</p>
                    @else
                        <table class="pg-team-detail__table pg-sales-details-table">
                            <thead>
                                <tr>
                                    @if ($this->selectedDealer() === null)
                                        <th>Dealer</th>
                                    @endif
                                    <th>ERP Order</th>
                                    <th class="pg-team-detail__num">ERP Amount</th>
                                    <th>ERP Date</th>
                                    <th>Tally Voucher No.</th>
                                    <th>Tally Date</th>
                                    <th class="pg-team-detail__num">Tally Amount</th>
                                    <th>Match reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['pairs'] as $pair)
                                    <tr>
                                        @if ($this->selectedDealer() === null)
                                            <td>{{ $pair['dealer_name'] }}</td>
                                        @endif
                                        <td>{{ $pair['erp_order'] }}</td>
                                        <td class="pg-team-detail__num">{{ $this->formatMoney($pair['erp_amount']) }}</td>
                                        <td>{{ $pair['erp_date'] ?: '—' }}</td>
                                        <td>{{ $pair['tally_voucher_no'] ?: '—' }}</td>
                                        <td>{{ $pair['tally_date'] ?: '—' }}</td>
                                        <td class="pg-team-detail__num">{{ $this->formatMoney($pair['tally_amount']) }}</td>
                                        <td>{{ $pair['match_reason_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="{{ $this->selectedDealer() === null ? 8 : 7 }}">
                                        Total duplicate amount {{ $this->formatMoney($report['duplicate_amount']) }}
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    @endif
                </div>

                @if ($report['ambiguous'] !== [])
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Needs review (not included in duplicate amount)</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Same-amount sales that are not a unique 1:1 match. These are left untouched.
                        </p>
                        <div class="pg-sales-details-table-wrap mt-3">
                            <table class="pg-team-detail__table pg-sales-details-table">
                                <thead>
                                    <tr>
                                        <th>Dealer</th>
                                        <th class="pg-team-detail__num">Amount</th>
                                        <th>ERP vouchers</th>
                                        <th>Tally vouchers</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($report['ambiguous'] as $row)
                                        <tr>
                                            <td>{{ $row['dealer_name'] }}</td>
                                            <td class="pg-team-detail__num">{{ $this->formatMoney($row['amount']) }}</td>
                                            <td>{{ $row['erp_vouchers'] }}</td>
                                            <td>{{ $row['tally_vouchers'] }}</td>
                                            <td>{{ $row['reason'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
