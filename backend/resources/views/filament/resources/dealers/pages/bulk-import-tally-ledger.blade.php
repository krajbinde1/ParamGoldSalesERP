@php
    $assignedDealers = $this->assignedDealers();
    $employeeLabel = $this->selectedEmployeeLabel();
    $previewRows = $previewRows ?? [];
    $resultRows = $resultRows ?? [];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([1 => 'Select employee & upload', 2 => 'Preview results', 3 => 'Import results'] as $n => $label)
                <div @class([
                    'rounded-xl border px-4 py-3',
                    'border-primary-500 bg-primary-50 text-primary-700' => $step === $n,
                    'border-gray-200 bg-white' => $step !== $n,
                ])>
                    <div class="text-xs font-semibold uppercase tracking-wide">Step {{ $n }}</div>
                    <div class="mt-1 text-sm font-medium">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        @if ($step === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950">Bulk Tally Ledger Import</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Select the assigned employee first, then upload multiple Tally Excel files together.
                        Each file is one dealer ledger and is matched by the ledger name inside the file.
                        Unmatched and error files are skipped. Already imported dealers can be imported again; duplicate transactions are skipped.
                        After you correct a dealer name or assignment in ERP, upload the same files again to retry unmatched ledgers.
                    </p>
                </div>

                <form wire:submit="previewUpload" class="space-y-5">
                    {{ $this->form }}
                    @if ($employeeLabel)
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,data.files">
                            <span wire:loading.remove wire:target="previewUpload,data.files">Preview results</span>
                            <span wire:loading wire:target="previewUpload,data.files">Reading Excel files…</span>
                        </x-filament::button>
                    @endif
                </form>
            </div>

            @if ($employeeLabel)
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-5">
                        <h3 class="text-base font-semibold text-slate-950">Dealers assigned to {{ $employeeLabel }}</h3>
                        <div class="text-sm text-slate-500">{{ count($assignedDealers) }} dealer{{ count($assignedDealers) === 1 ? '' : 's' }}</div>
                    </div>
                    @if ($assignedDealers === [])
                        <p class="border-t border-gray-200 px-6 py-5 text-sm text-slate-600">No dealers are assigned to this employee.</p>
                    @else
                        <div class="fi-ta-ctn border-t border-gray-200">
                            <div class="fi-ta-content relative overflow-x-auto">
                                <table class="fi-ta-table w-full table-fixed divide-y divide-gray-200 text-sm">
                                    <colgroup>
                                        <col style="width: 40%">
                                        <col style="width: 16%">
                                        <col style="width: 24%">
                                        <col style="width: 20%">
                                    </colgroup>
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Dealer</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Code</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Village</th>
                                            <th class="fi-ta-header-cell px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-600">Ledger Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        @foreach ($assignedDealers as $index => $dealer)
                                            <tr @class(['fi-ta-row', 'bg-gray-50/70' => $index % 2 === 1])>
                                                <td class="fi-ta-cell truncate px-4 py-3 font-medium text-slate-900" title="{{ $dealer['firm_name'] }}">{{ $dealer['firm_name'] }}</td>
                                                <td class="fi-ta-cell whitespace-nowrap px-4 py-3">{{ $dealer['dealer_code'] ?: '—' }}</td>
                                                <td class="fi-ta-cell truncate px-4 py-3" title="{{ $dealer['village'] ?: '—' }}">{{ $dealer['village'] ?: '—' }}</td>
                                                <td class="fi-ta-cell whitespace-nowrap px-4 py-3">{{ $dealer['tally_status'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endif

        @if ($step === 2)
            @php
                $summary = $this->previewSummary();
                $matchedRows = $this->matchedRows();
                $notMatchedRows = $this->notMatchedRows();
                $errorRows = $this->errorRows();
            @endphp

            <div class="grid gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => 'Total Files', 'value' => $summary['total'], 'tone' => 'slate'],
                    ['label' => 'Matched', 'value' => $summary['matched'], 'tone' => 'emerald'],
                    ['label' => 'Not Matched', 'value' => $summary['not_matched'], 'tone' => 'amber'],
                    ['label' => 'Error', 'value' => $summary['error'], 'tone' => 'rose'],
                ] as $card)
                    <div @class([
                        'rounded-xl border px-4 py-3',
                        'border-slate-200 bg-slate-50' => $card['tone'] === 'slate',
                        'border-emerald-200 bg-emerald-50' => $card['tone'] === 'emerald',
                        'border-amber-200 bg-amber-50' => $card['tone'] === 'amber',
                        'border-rose-200 bg-rose-50' => $card['tone'] === 'rose',
                    ])>
                        <div @class([
                            'text-xs font-semibold uppercase tracking-wide',
                            'text-slate-500' => $card['tone'] === 'slate',
                            'text-emerald-700' => $card['tone'] === 'emerald',
                            'text-amber-800' => $card['tone'] === 'amber',
                            'text-rose-700' => $card['tone'] === 'rose',
                        ])>{{ $card['label'] }}</div>
                        <div @class([
                            'mt-1 text-2xl font-bold',
                            'text-slate-900' => $card['tone'] === 'slate',
                            'text-emerald-900' => $card['tone'] === 'emerald',
                            'text-amber-950' => $card['tone'] === 'amber',
                            'text-rose-900' => $card['tone'] === 'rose',
                        ])>{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex flex-col gap-4 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">Preview results</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Employee: <strong>{{ $employeeLabel }}</strong>.
                            Only <strong>Matched</strong> files will be imported. Not Matched and Error files are skipped.
                            After you correct a dealer name or assignment in ERP, upload the same files again to retry.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="resetUpload">Upload other files</x-filament::button>
                        @if ($notMatchedRows !== [])
                            <x-filament::button color="gray" wire:click="downloadNotMatchedReport" wire:loading.attr="disabled" wire:target="downloadNotMatchedReport">
                                <span wire:loading.remove wire:target="downloadNotMatchedReport">Download Not Matched Report</span>
                                <span wire:loading wire:target="downloadNotMatchedReport">Preparing Excel…</span>
                            </x-filament::button>
                        @endif
                        @if ($matchedRows !== [] && collect($matchedRows)->contains(fn (array $row): bool => ! empty($row['can_import'])))
                            <x-filament::button wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">
                                <span wire:loading.remove wire:target="runImport">Bulk Import</span>
                                <span wire:loading wire:target="runImport">Importing…</span>
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-950">Matched dealers</h3>
                    <p class="mt-1 text-sm text-slate-600">These files will be imported for the selected employee.</p>
                </div>
                <div class="border-t border-gray-200">
                    @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                        'rows' => $matchedRows,
                        'showImportResult' => false,
                    ])
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-amber-200 bg-white">
                <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-slate-950">Not Matched Dealers</h3>
                        <p class="mt-1 text-sm text-slate-600">These files are skipped. Fix the dealer name or assignment in ERP, then upload the same files again.</p>
                    </div>
                </div>
                <div class="border-t border-amber-200">
                    @include('filament.resources.dealers.pages.partials.bulk-tally-not-matched-rows', [
                        'rows' => $notMatchedRows,
                        'employeeLabel' => $employeeLabel,
                    ])
                </div>
            </div>

            @if ($errorRows !== [])
                <div class="overflow-hidden rounded-xl border border-rose-200 bg-white">
                    <div class="px-6 py-4">
                        <h3 class="text-base font-semibold text-slate-950">Error files</h3>
                        <p class="mt-1 text-sm text-slate-600">These files could not be read or parsed and will not be imported.</p>
                    </div>
                    <div class="border-t border-rose-200">
                        @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                            'rows' => $errorRows,
                            'showImportResult' => false,
                        ])
                    </div>
                </div>
            @endif
        @endif

        @if ($step === 3)
            @php
                $summary = $this->previewSummary();
                $matchedRows = $this->matchedRows();
                $notMatchedRows = $this->notMatchedRows();
                $errorRows = $this->errorRows();
            @endphp

            <div class="grid gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => 'Total Files', 'value' => $summary['total'], 'tone' => 'slate'],
                    ['label' => 'Matched', 'value' => $summary['matched'], 'tone' => 'emerald'],
                    ['label' => 'Not Matched', 'value' => $summary['not_matched'], 'tone' => 'amber'],
                    ['label' => 'Error', 'value' => $summary['error'], 'tone' => 'rose'],
                ] as $card)
                    <div @class([
                        'rounded-xl border px-4 py-3',
                        'border-slate-200 bg-slate-50' => $card['tone'] === 'slate',
                        'border-emerald-200 bg-emerald-50' => $card['tone'] === 'emerald',
                        'border-amber-200 bg-amber-50' => $card['tone'] === 'amber',
                        'border-rose-200 bg-rose-50' => $card['tone'] === 'rose',
                    ])>
                        <div @class([
                            'text-xs font-semibold uppercase tracking-wide',
                            'text-slate-500' => $card['tone'] === 'slate',
                            'text-emerald-700' => $card['tone'] === 'emerald',
                            'text-amber-800' => $card['tone'] === 'amber',
                            'text-rose-700' => $card['tone'] === 'rose',
                        ])>{{ $card['label'] }}</div>
                        <div @class([
                            'mt-1 text-2xl font-bold',
                            'text-slate-900' => $card['tone'] === 'slate',
                            'text-emerald-900' => $card['tone'] === 'emerald',
                            'text-amber-950' => $card['tone'] === 'amber',
                            'text-rose-900' => $card['tone'] === 'rose',
                        ])>{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex flex-col gap-4 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">Import results</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Employee: <strong>{{ $employeeLabel }}</strong>.
                            Only Matched files were imported. Not Matched and Error files were skipped.
                            After you correct a dealer name or assignment in ERP, upload the same files again to retry.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        @if ($notMatchedRows !== [])
                            <x-filament::button color="gray" wire:click="downloadNotMatchedReport" wire:loading.attr="disabled" wire:target="downloadNotMatchedReport">
                                <span wire:loading.remove wire:target="downloadNotMatchedReport">Download Not Matched Report</span>
                                <span wire:loading wire:target="downloadNotMatchedReport">Preparing Excel…</span>
                            </x-filament::button>
                        @endif
                        <x-filament::button color="gray" wire:click="resetUpload">Import other files</x-filament::button>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-950">Matched dealers</h3>
                </div>
                <div class="border-t border-gray-200">
                    @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                        'rows' => $matchedRows,
                        'showImportResult' => true,
                    ])
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-amber-200 bg-white">
                <div class="px-6 py-4">
                    <h3 class="text-base font-semibold text-slate-950">Not Matched Dealers</h3>
                </div>
                <div class="border-t border-amber-200">
                    @include('filament.resources.dealers.pages.partials.bulk-tally-not-matched-rows', [
                        'rows' => $notMatchedRows,
                        'employeeLabel' => $employeeLabel,
                    ])
                </div>
            </div>

            @if ($errorRows !== [])
                <div class="overflow-hidden rounded-xl border border-rose-200 bg-white">
                    <div class="px-6 py-4">
                        <h3 class="text-base font-semibold text-slate-950">Error files</h3>
                    </div>
                    <div class="border-t border-rose-200">
                        @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                            'rows' => $errorRows,
                            'showImportResult' => true,
                        ])
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
