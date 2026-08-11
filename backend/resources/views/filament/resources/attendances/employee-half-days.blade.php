<x-filament-panels::page>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr class="text-left">
                    <th class="px-4 py-3">Attendance Date</th>
                    <th class="px-4 py-3">Punch In Time (IST)</th>
                    <th class="px-4 py-3">Punch Out Time (IST)</th>
                    <th class="px-4 py-3">Working Hours</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">View</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($this->getRows() as $row)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ \Illuminate\Support\Carbon::parse($row['attendance_date'])->format('d M Y') }}
                        </td>
                        <td class="px-4 py-3">{{ $row['punch_in_time'] }}</td>
                        <td class="px-4 py-3">{{ $row['punch_out_time'] }}</td>
                        <td class="px-4 py-3">{{ $row['working_hours'] }}</td>
                        <td class="px-4 py-3">{{ $row['status'] }}</td>
                        <td class="px-4 py-3">
                            <a
                                href="{{ \App\Filament\Resources\Attendances\AttendanceResource::getUrl('view', ['record' => $row['attendance_id']]) }}"
                                class="text-primary-600 hover:underline"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            No half days found for this employee in the selected month.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
