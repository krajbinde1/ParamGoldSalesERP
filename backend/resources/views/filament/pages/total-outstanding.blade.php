<x-filament-panels::page>
    <div class="total-outstanding-page space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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

        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-4">
            {{ $this->form }}
        </div>

        @if ($this->selectedEmployeeId() === null)
            <x-filament::section>
                <x-slot name="heading">Outstanding by Employee</x-slot>
                <x-slot name="description">
                    Select an employee to see assigned dealers, outstanding amounts, and export.
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="px-3 py-2.5">Employee</th>
                                <th class="px-3 py-2.5">Dealers</th>
                                <th class="px-3 py-2.5 text-right">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->employeeOutstandingRows() as $row)
                                <tr
                                    wire:click="selectEmployee({{ $row['employee_id'] }})"
                                    class="cursor-pointer border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
                                >
                                    <td class="px-3 py-2.5 font-medium text-gray-950 dark:text-white">
                                        {{ $row['employee_name'] }}
                                        @if (filled($row['employee_code']))
                                            <span class="font-normal text-gray-500"> · {{ $row['employee_code'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $row['dealer_count'] }}</td>
                                    <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                        {{ $this->formatMoney((float) $row['total_outstanding']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-8 text-center text-gray-500">
                                        No employee outstanding to show.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <div class="overflow-x-auto">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
