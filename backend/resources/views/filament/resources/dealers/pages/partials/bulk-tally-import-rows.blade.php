@php
    use App\Filament\Resources\Dealers\DealerResource;
@endphp

<style>
    .erp-bulk-tally-rows {
        direction: ltr;
    }

    .erp-bulk-tally-rows table {
        table-layout: fixed;
        width: 100%;
        min-width: 56rem;
        border-collapse: separate;
        border-spacing: 0;
    }

    .erp-bulk-tally-rows.erp-bulk-tally-rows--results table {
        min-width: 76rem;
    }

    .erp-bulk-tally-rows th {
        position: relative;
        z-index: 1;
        white-space: nowrap;
        background-color: rgb(249 250 251);
        box-shadow: inset 0 -1px 0 rgb(229 231 235);
    }

    .erp-bulk-tally-rows td,
    .erp-bulk-tally-rows th {
        vertical-align: middle;
    }

    .erp-bulk-tally-rows .erp-col-num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }

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

    .erp-bulk-tally-rows .erp-status-reason {
        display: block;
        margin-top: 0.25rem;
        overflow-wrap: anywhere;
        word-break: break-word;
        white-space: normal;
        line-height: 1.35;
    }
</style>

<div @class([
    'erp-bulk-tally-rows',
    'erp-bulk-tally-rows--results' => $showImportResult,
])>
    <div class="relative overflow-x-auto">
        <table class="w-full table-fixed divide-y divide-gray-200 text-sm dark:divide-white/10">
            <colgroup>
                <col style="width: {{ $showImportResult ? '22%' : '28%' }}">
                <col style="width: {{ $showImportResult ? '16%' : '24%' }}">
                <col style="width: {{ $showImportResult ? '16%' : '24%' }}">
                <col style="width: {{ $showImportResult ? '14%' : '24%' }}">
                @if ($showImportResult)
                    <col style="width: 8%">
                    <col style="width: 12%">
                    <col style="width: 12%">
                @endif
            </colgroup>
            <thead>
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">File Name</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Detected Dealer</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Matched Dealer</th>
                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Status</th>
                    @if ($showImportResult)
                        <th class="erp-col-num px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Imported</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Ledger Status</th>
                        <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Ledger</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @forelse ($rows as $index => $row)
                    @php
                        $status = (string) ($row['status'] ?? '');
                    @endphp
                    <tr @class(['bg-gray-50/70 dark:bg-white/[0.03]' => $index % 2 === 1])>
                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">
                            <div class="erp-cell-clip" title="{{ $row['file_name'] ?? '—' }}">{{ $row['file_name'] ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $row['detected_dealer'] ?? '—' }}">{{ $row['detected_dealer'] ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <div class="erp-cell-clip" title="{{ $row['matched_dealer'] ?? '—' }}">{{ $row['matched_dealer'] ?? '—' }}</div>
                            @if (! empty($row['dealer_code']))
                                <div class="mt-0.5 text-xs text-gray-500">{{ $row['dealer_code'] }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span @class([
                                'erp-status-badge rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-50 text-emerald-800' => $status === 'Matched',
                                'bg-amber-50 text-amber-900' => $status === 'Not Matched',
                                'bg-sky-50 text-sky-800' => $status === 'Already Imported',
                                'bg-rose-50 text-rose-800' => $status === 'Error',
                                'bg-slate-100 text-slate-700' => ! in_array($status, ['Matched', 'Not Matched', 'Already Imported', 'Error'], true),
                            ])>{{ $status !== '' ? $status : '—' }}</span>
                            @if (! empty($row['reason']) && $status !== 'Matched')
                                <span class="erp-status-reason text-xs text-slate-600">{{ $row['reason'] }}</span>
                            @endif
                        </td>
                        @if ($showImportResult)
                            <td class="erp-col-num px-4 py-3 text-gray-950 dark:text-white">
                                {{ ($row['import_status_label'] ?? '') === 'Ledger Imported' ? (int) ($row['imported_count'] ?? 0) : '—' }}
                                @if ((int) ($row['reconciled_count'] ?? 0) > 0)
                                    <div class="text-xs font-normal text-slate-500">{{ (int) $row['reconciled_count'] }} sales order{{ (int) $row['reconciled_count'] === 1 ? '' : 's' }} reconciled</div>
                                @endif
                                @if ((int) ($row['duplicate_count'] ?? 0) > 0)
                                    <div class="text-xs font-normal text-slate-500">{{ (int) $row['duplicate_count'] }} duplicate{{ (int) $row['duplicate_count'] === 1 ? '' : 's' }} skipped</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                {{ $row['tally_status'] ?? 'Not Imported' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if (! empty($row['dealer_id']))
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        tag="a"
                                        :href="DealerResource::getUrl('ledger', ['record' => $row['dealer_id']])"
                                    >
                                        Open ledger
                                    </x-filament::button>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showImportResult ? 7 : 4 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No Tally Excel files were found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
