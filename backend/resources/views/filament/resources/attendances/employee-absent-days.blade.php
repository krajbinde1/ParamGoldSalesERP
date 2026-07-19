<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr class="text-left">
                    <th class="px-4 py-3">Absent Date</th>
                    <th class="px-4 py-3">Day Name</th>
                    <th class="px-4 py-3">Reason</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($this->getRows() as $row)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ \Illuminate\Support\Carbon::parse($row['absent_date'])->format('d M Y') }}
                        </td>
                        <td class="px-4 py-3">{{ $row['day_name'] }}</td>
                        <td class="px-4 py-3">{{ $row['reason'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-500">
                            No absent working days found for this employee in the selected month.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
