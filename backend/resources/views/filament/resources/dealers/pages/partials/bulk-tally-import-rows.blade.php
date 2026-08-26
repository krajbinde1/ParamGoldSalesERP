@php
    use App\Filament\Resources\Dealers\DealerResource;
@endphp

<div class="overflow-x-auto rounded-lg border border-slate-200">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
                <th class="px-3 py-2 text-left">Dealer Name</th>
                <th class="px-3 py-2 text-left">Matched / Not Matched</th>
                <th class="px-3 py-2 text-left">Ledger Imported / Failed</th>
                <th class="px-3 py-2 text-right">Transactions Imported</th>
                <th class="px-3 py-2 text-right">Closing Balance</th>
                @if ($showImportResult)
                    <th class="px-3 py-2 text-left">Ledger</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-slate-100 align-top">
                    <td class="px-3 py-2">
                        <div class="font-medium text-slate-900">{{ $row['dealer_name'] }}</div>
                        @if (! empty($row['dealer_code']))
                            <div class="text-xs text-slate-500">{{ $row['dealer_code'] }}</div>
                        @endif
                        @if (($row['tally_ledger_name'] ?? '') !== '' && ($row['tally_ledger_name'] ?? '') !== ($row['dealer_name'] ?? ''))
                            <div class="text-xs text-slate-500">Tally: {{ $row['tally_ledger_name'] }}</div>
                        @endif
                        @if (! empty($row['reason']) && empty($row['matched']))
                            <div class="mt-1 text-xs text-amber-800">{{ $row['reason'] }}</div>
                        @endif
                        @if (! empty($row['reason']) && ! empty($row['matched']) && ($row['import_status_label'] ?? '') === 'Failed')
                            <div class="mt-1 text-xs text-rose-800">{{ $row['reason'] }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span @class([
                            'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                            'bg-emerald-50 text-emerald-800' => ! empty($row['matched']),
                            'bg-amber-50 text-amber-900' => empty($row['matched']),
                        ])>{{ $row['match_label'] }}</span>
                    </td>
                    <td class="px-3 py-2">
                        @if ($showImportResult)
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-emerald-50 text-emerald-800' => ($row['import_status_label'] ?? '') === 'Ledger Imported',
                                'bg-rose-50 text-rose-800' => ($row['import_status_label'] ?? '') === 'Failed',
                                'bg-slate-100 text-slate-700' => ! in_array($row['import_status_label'] ?? '', ['Ledger Imported', 'Failed'], true),
                            ])>{{ $row['import_status_label'] ?: 'Not Imported' }}</span>
                        @else
                            <span class="text-slate-500">{{ ! empty($row['can_import']) ? 'Ready to import' : 'Will not import' }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        @if ($showImportResult)
                            {{ (int) ($row['imported_count'] ?? 0) }}
                            @if ((int) ($row['duplicate_count'] ?? 0) > 0)
                                <div class="text-xs text-slate-500">{{ (int) $row['duplicate_count'] }} duplicate{{ (int) $row['duplicate_count'] === 1 ? '' : 's' }} skipped</div>
                            @endif
                        @else
                            {{ (int) ($row['transaction_count'] ?? 0) }} in file
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums font-medium">{{ $row['closing_balance_label'] ?? '—' }}</td>
                    @if ($showImportResult)
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
                    <td colspan="{{ $showImportResult ? 6 : 5 }}" class="px-3 py-6 text-center text-slate-500">
                        No Tally ledgers were found in this file.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
