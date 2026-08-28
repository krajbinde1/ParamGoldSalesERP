@php
    $employeeLabel = $employeeLabel ?? '—';
@endphp

<style>
    .erp-bulk-tally-rows .erp-cell-clip {
        display: -webkit-box;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .erp-bulk-tally-rows .erp-status-badge {
        display: inline-flex;
        max-width: 100%;
        align-items: center;
        white-space: nowrap;
    }
</style>

<div class="erp-bulk-tally-rows">
    <div class="relative overflow-x-auto">
        <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-white/10">
            <colgroup>
                <col style="width: 18%">
                <col style="width: 18%">
                <col style="width: 16%">
                <col style="width: 18%">
                <col style="width: 18%">
                <col style="width: 12%">
            </colgroup>
            <thead>
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">File Name</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Tally / Detected Dealer</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Selected Employee</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Reason for Not Match</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Suggested Dealer</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @forelse ($rows as $index => $row)
                    @php
                        $suggested = trim((string) ($row['suggested_dealer'] ?? ''));
                        $employee = trim(implode(' — ', array_filter([
                            (string) ($row['employee_code'] ?? ''),
                            (string) ($row['employee_name'] ?? ''),
                        ]))) ?: $employeeLabel;
                    @endphp
                    <tr @class(['bg-gray-50/70 dark:bg-white/[0.03]' => $index % 2 === 1])>
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            <div class="erp-cell-clip" title="{{ $row['file_name'] ?? '—' }}">{{ $row['file_name'] ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $row['detected_dealer'] ?? '—' }}">{{ $row['detected_dealer'] ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $employee }}">{{ $employee !== '' ? $employee : '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $row['reason'] ?? '—' }}">{{ $row['reason'] ?: '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $suggested !== '' ? $suggested : '—' }}">{{ $suggested !== '' ? $suggested : '—' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="erp-status-badge rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">Not Matched</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No unmatched Tally files.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
