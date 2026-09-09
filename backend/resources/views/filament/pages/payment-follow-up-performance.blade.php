<x-filament-panels::page>
    <div class="inventory-reports-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-4">
        {{ $this->form }}
    </div>

    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
        Employee follow-up discipline uses existing dealer assignments. Due Today, Overdue, and No Follow-up are current. Completed and Payments Received respect the date range.
    </p>

    <div class="mt-4 overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead>
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="px-4 py-3 text-start font-semibold">Employee</th>
                    <th class="px-4 py-3 text-end font-semibold">Assigned Dealers</th>
                    <th class="px-4 py-3 text-end font-semibold">With Outstanding</th>
                    <th class="px-4 py-3 text-end font-semibold">Due Today</th>
                    <th class="px-4 py-3 text-end font-semibold">Overdue</th>
                    <th class="px-4 py-3 text-end font-semibold">No Follow-up</th>
                    <th class="px-4 py-3 text-end font-semibold">Follow-ups Completed</th>
                    <th class="px-4 py-3 text-end font-semibold">Payments Received</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @forelse ($this->rows() as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3 font-medium">
                            {{ $row['employee_name'] }}
                            @if (filled($row['employee_code']))
                                <span class="font-normal text-gray-500">· {{ $row['employee_code'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['assigned_dealers'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['dealers_with_outstanding'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['due_today'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['overdue'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['no_follow_up'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['follow_ups_completed'] }}</td>
                        <td class="px-4 py-3 text-end tabular-nums">{{ $row['payments_received'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-gray-500">No sales employees with assigned dealers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
