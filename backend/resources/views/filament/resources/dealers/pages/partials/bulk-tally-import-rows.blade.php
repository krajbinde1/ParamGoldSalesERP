@php
    use App\Filament\Resources\Dealers\DealerResource;
@endphp

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
                <th class="px-3 py-2 text-left">File Name</th>
                <th class="px-3 py-2 text-left">Detected Dealer</th>
                <th class="px-3 py-2 text-left">Matched Dealer</th>
                <th class="px-3 py-2 text-left">Status</th>
                @if ($showImportResult)
                    <th class="px-3 py-2 text-right">Transactions Imported</th>
                    <th class="px-3 py-2 text-left">Tally Ledger Status</th>
                    <th class="px-3 py-2 text-left">Ledger</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $status = (string) ($row['status'] ?? '');
                @endphp
                <tr class="border-t border-slate-100 align-top">
                    <td class="px-3 py-2 font-medium text-slate-900">{{ $row['file_name'] ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $row['detected_dealer'] ?? '—' }}</td>
                    <td class="px-3 py-2">
                        <div>{{ $row['matched_dealer'] ?? '—' }}</div>
                        @if (! empty($row['dealer_code']))
                            <div class="text-xs text-slate-500">{{ $row['dealer_code'] }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span @class([
                            'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                            'bg-emerald-50 text-emerald-800' => $status === 'Matched',
                            'bg-amber-50 text-amber-900' => $status === 'Not Matched',
                            'bg-sky-50 text-sky-800' => $status === 'Already Imported',
                            'bg-rose-50 text-rose-800' => $status === 'Error',
                            'bg-slate-100 text-slate-700' => ! in_array($status, ['Matched', 'Not Matched', 'Already Imported', 'Error'], true),
                        ])>{{ $status !== '' ? $status : '—' }}</span>
                        @if (! empty($row['reason']) && $status !== 'Matched')
                            <div class="mt-1 text-xs text-slate-600">{{ $row['reason'] }}</div>
                        @endif
                        @if ($showImportResult && ($row['import_status_label'] ?? '') === 'Ledger Imported')
                            <div class="mt-1 text-xs font-medium text-emerald-800">Imported</div>
                        @endif
                    </td>
                    @if ($showImportResult)
                        <td class="px-3 py-2 text-right tabular-nums">
                            {{ (int) ($row['imported_count'] ?? 0) }}
                            @if ((int) ($row['duplicate_count'] ?? 0) > 0)
                                <div class="text-xs text-slate-500">{{ (int) $row['duplicate_count'] }} duplicate{{ (int) $row['duplicate_count'] === 1 ? '' : 's' }} skipped</div>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $row['tally_status'] ?? 'Not Imported' }}</td>
                        <td class="px-3 py-2">
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
                                —
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showImportResult ? 7 : 4 }}" class="px-3 py-6 text-center text-slate-500">
                        No Tally Excel files were found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
