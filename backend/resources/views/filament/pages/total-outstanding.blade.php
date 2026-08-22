<x-filament-panels::page>
    <div class="total-outstanding-page space-y-4">
        <div class="paramgold-summary-grid">
            <div class="paramgold-summary-card paramgold-summary-card--danger">
                <p class="paramgold-summary-card__label">
                    {{ $this->selectedEmployeeId() === null ? 'Total Outstanding' : 'Employee Outstanding' }}
                </p>
                <p class="paramgold-summary-card__value">
                    {{ $this->formattedTotalOutstanding() }}
                </p>
            </div>

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
                                    <th class="fi-ta-header-cell w-28 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Dealers
                                    </th>
                                    <th class="fi-ta-header-cell w-44 px-4 py-3 text-end text-sm font-semibold text-gray-950 dark:text-white">
                                        Outstanding
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
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="fi-ta-cell px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
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
