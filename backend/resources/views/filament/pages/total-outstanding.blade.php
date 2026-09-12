<x-filament-panels::page>
    <div class="total-outstanding-page space-y-4">
        @php
            $tally = $this->liveTallyReconciliation();
        @endphp

        <section class="space-y-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Live Tally Reconciliation</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Compared with the latest Live Tally balances stored by the office connector.
                </p>
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ $tally['connector_label'] ?? 'Tally Disconnected' }}
                    · Last Heartbeat: {{ $tally['last_heartbeat_label'] ?? '—' }}
                </p>
            </div>

            @if (filled($tally['banner_label']))
                <div
                    @class([
                        'rounded-xl border px-4 py-3 text-sm font-semibold',
                        'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-200' => $tally['banner'] === 'matched',
                        'border-orange-200 bg-orange-50 text-orange-900 dark:border-orange-800 dark:bg-orange-950/40 dark:text-orange-200' => $tally['banner'] === 'mismatch',
                    ])
                >
                    {{ $tally['banner_label'] }}
                </div>
            @endif

            <div class="paramgold-summary-grid">
                <button
                    type="button"
                    wire:click="filterTallyStatus('matched')"
                    aria-pressed="{{ $this->tallyStatusFilter === 'matched' ? 'true' : 'false' }}"
                    @class([
                        'paramgold-summary-card paramgold-summary-card--success paramgold-summary-card--clickable',
                        'paramgold-summary-card--active' => $this->tallyStatusFilter === 'matched',
                    ])
                >
                    <p class="paramgold-summary-card__label">Matched Dealers</p>
                    <p class="paramgold-summary-card__value">{{ $tally['matched'] }}</p>
                </button>

                <button
                    type="button"
                    wire:click="filterTallyStatus('mismatch')"
                    aria-pressed="{{ $this->tallyStatusFilter === 'mismatch' ? 'true' : 'false' }}"
                    @class([
                        'paramgold-summary-card paramgold-summary-card--warning paramgold-summary-card--clickable',
                        'paramgold-summary-card--active' => $this->tallyStatusFilter === 'mismatch',
                    ])
                >
                    <p class="paramgold-summary-card__label">Mismatched Dealers</p>
                    <p class="paramgold-summary-card__value">{{ $tally['mismatched'] }}</p>
                </button>

                <button
                    type="button"
                    wire:click="filterTallyStatus('not_synced')"
                    aria-pressed="{{ $this->tallyStatusFilter === 'not_synced' ? 'true' : 'false' }}"
                    @class([
                        'paramgold-summary-card paramgold-summary-card--info paramgold-summary-card--clickable',
                        'paramgold-summary-card--active' => $this->tallyStatusFilter === 'not_synced',
                    ])
                >
                    <p class="paramgold-summary-card__label">Not Synced Dealers</p>
                    <p class="paramgold-summary-card__value">{{ $tally['not_synced'] }}</p>
                </button>

                <div class="paramgold-summary-card paramgold-summary-card--primary">
                    <p class="paramgold-summary-card__label">Last Live Tally Sync</p>
                    <p class="paramgold-summary-card__value paramgold-summary-card__value--wrap">
                        {{ $tally['last_synced_label'] }}
                    </p>
                </div>
            </div>
        </section>

        <div class="paramgold-summary-grid">
            <div class="paramgold-summary-card paramgold-summary-card--danger">
                <p class="paramgold-summary-card__label">Total Outstanding</p>
                <p class="paramgold-summary-card__value">
                    {{ $this->formattedTotalOutstanding() }}
                </p>
            </div>

            <div class="paramgold-summary-card paramgold-summary-card--success">
                <p class="paramgold-summary-card__label">Total Credit Balance</p>
                <p class="paramgold-summary-card__value">
                    {{ $this->formattedCreditBalance() }}
                </p>
            </div>

            @if ($this->hasCreditBalance())
                <div class="paramgold-summary-card paramgold-summary-card--info">
                    <p class="paramgold-summary-card__label">Net Balance</p>
                    <p class="paramgold-summary-card__value">
                        {{ $this->formattedNetBalance() }}
                    </p>
                </div>
            @endif

            @if ($this->selectedEmployeeId() !== null)
                <div class="paramgold-summary-card paramgold-summary-card--primary">
                    <p class="paramgold-summary-card__label">Assigned Dealers</p>
                    <p class="paramgold-summary-card__value">{{ $this->assignedDealerCount() }}</p>
                </div>
            @endif
        </div>

        <div class="inventory-reports-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-4">
            {{ $this->form }}
        </div>

        @if ($this->selectedEmployeeId() === null)
            <x-filament::section>
                <x-slot name="heading">Outstanding by Employee</x-slot>
                <x-slot name="description">
                    Select an employee to see assigned dealers and outstanding amounts.
                </x-slot>

                <div class="fi-ta-ctn overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10">
                        <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/10">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="fi-ta-header-cell px-4 py-3 text-start text-sm font-semibold text-gray-950 dark:text-white">
                                        Employee
                                    </th>
                                    <th class="fi-ta-header-cell w-24 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Dealers
                                    </th>
                                    <th class="fi-ta-header-cell w-40 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Outstanding
                                    </th>
                                    <th class="fi-ta-header-cell w-40 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Credit Balance
                                    </th>
                                    <th class="fi-ta-header-cell w-40 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Net Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @forelse ($this->employeeOutstandingRows() as $index => $row)
                                    <tr
                                        wire:click="selectEmployee({{ $row['employee_id'] }})"
                                        @class([
                                            'fi-ta-row cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5',
                                            'bg-gray-50/80 dark:bg-white/[0.03]' => $index % 2 === 1,
                                        ])
                                    >
                                        <td class="fi-ta-cell px-4 py-3 text-sm font-medium text-gray-950 dark:text-white">
                                            {{ $row['employee_name'] }}
                                            @if (filled($row['employee_code']))
                                                <span class="ms-1 font-normal text-gray-500 dark:text-gray-400">
                                                    · {{ $row['employee_code'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="fi-ta-cell px-4 py-3 text-end text-sm tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $row['dealer_count'] }}
                                        </td>
                                        <td class="fi-ta-cell px-4 py-3 text-end text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ $this->formatMoney((float) $row['total_outstanding']) }}
                                        </td>
                                        <td class="fi-ta-cell px-4 py-3 text-end text-sm tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ $this->formatMoney((float) $row['total_credit']) }}
                                        </td>
                                        <td class="fi-ta-cell px-4 py-3 text-end text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                            {{ $this->formatMoney((float) $row['net_balance']) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="fi-ta-cell px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                            No employee outstanding to show.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <div class="inventory-reports-table-wrap">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
