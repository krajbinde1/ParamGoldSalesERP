<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $this->selectedEmployeeId() === null ? 'Total Outstanding' : 'Employee Outstanding' }}
                </p>
                <p class="mt-1 text-3xl font-semibold tracking-tight text-danger-600 dark:text-danger-400">
                    {{ $this->formattedTotalOutstanding() }}
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($this->selectedEmployeeId() === null)
                        Company outstanding across all dealers
                    @else
                        Outstanding for dealers assigned to the selected employee
                    @endif
                </p>
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-5">
            {{ $this->form }}
        </div>

        @if ($this->selectedEmployeeId() === null)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 sm:p-5">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Outstanding by Employee</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Select an employee to see their total and dealer-wise outstanding.
                </p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-3 font-medium">Employee</th>
                                <th class="py-2 pr-3 font-medium">Dealers</th>
                                <th class="py-2 text-right font-medium">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->employeeOutstandingRows() as $row)
                                <tr
                                    wire:click="selectEmployee({{ $row['employee_id'] }})"
                                    class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5"
                                >
                                    <td class="py-2.5 pr-3 font-medium text-gray-950 dark:text-white">
                                        {{ $row['employee_name'] }}
                                        @if (filled($row['employee_code']))
                                            <span class="font-normal text-gray-500">· {{ $row['employee_code'] }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-3 text-gray-600 dark:text-gray-300">{{ $row['dealer_count'] }}</td>
                                    <td class="py-2.5 text-right font-semibold text-danger-600 dark:text-danger-400">
                                        {{ $this->formatMoney((float) $row['total_outstanding']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="py-6 text-center text-gray-500">
                                        No employee outstanding to show.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="overflow-x-auto">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
