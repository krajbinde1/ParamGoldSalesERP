@php
    $assignedDealers = $this->assignedDealers();
    $employeeLabel = $this->selectedEmployeeLabel();
    $previewRows = $previewRows ?? [];
    $resultRows = $resultRows ?? [];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([1 => 'Select employee & upload', 2 => 'Match preview', 3 => 'Import results'] as $n => $label)
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
                        Select the assigned employee first. Only that employee’s dealers are shown and matched.
                        Parties in the Excel that do not match those dealers are skipped.
                        The existing single-dealer Import Tally Ledger is unchanged.
                    </p>
                </div>

                <form wire:submit="previewUpload" class="space-y-5">
                    {{ $this->form }}
                    @if ($employeeLabel)
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,data.file">
                            <span wire:loading.remove wire:target="previewUpload,data.file">Preview matches</span>
                            <span wire:loading wire:target="previewUpload,data.file">Reading Excel…</span>
                        </x-filament::button>
                    @endif
                </form>
            </div>

            @if ($employeeLabel)
                <div class="rounded-xl border border-slate-200 bg-white p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-base font-semibold text-slate-950">Dealers assigned to {{ $employeeLabel }}</h3>
                        <div class="text-sm text-slate-500">{{ count($assignedDealers) }} dealer{{ count($assignedDealers) === 1 ? '' : 's' }}</div>
                    </div>
                    @if ($assignedDealers === [])
                        <p class="mt-3 text-sm text-slate-600">No dealers are assigned to this employee.</p>
                    @else
                        <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Dealer</th>
                                        <th class="px-3 py-2 text-left">Code</th>
                                        <th class="px-3 py-2 text-left">Village</th>
                                        <th class="px-3 py-2 text-left">Tally Ledger Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($assignedDealers as $dealer)
                                        <tr class="border-t border-slate-100">
                                            <td class="px-3 py-2 font-medium text-slate-900">{{ $dealer['firm_name'] }}</td>
                                            <td class="px-3 py-2">{{ $dealer['dealer_code'] ?: '—' }}</td>
                                            <td class="px-3 py-2">{{ $dealer['village'] ?: '—' }}</td>
                                            <td class="px-3 py-2">{{ $dealer['tally_status'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        @endif

        @if ($step === 2)
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">Match preview</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Employee: <strong>{{ $employeeLabel }}</strong>.
                            Unmatched parties will not be imported.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="resetUpload">Upload another file</x-filament::button>
                        @if (collect($previewRows)->contains(fn (array $row): bool => ! empty($row['can_import'])))
                            <x-filament::button wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport">
                                <span wire:loading.remove wire:target="runImport">Confirm &amp; import matched ledgers</span>
                                <span wire:loading wire:target="runImport">Importing…</span>
                            </x-filament::button>
                        @endif
                    </div>
                </div>

                @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                    'rows' => $previewRows,
                    'showImportResult' => false,
                ])
            </div>
        @endif

        @if ($step === 3)
            <div class="rounded-xl border border-gray-200 bg-white p-6 space-y-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950">Import results</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            Employee: <strong>{{ $employeeLabel }}</strong>.
                            Open a dealer ledger to verify the imported transactions.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="resetUpload">Import another file</x-filament::button>
                    </div>
                </div>

                @include('filament.resources.dealers.pages.partials.bulk-tally-import-rows', [
                    'rows' => $resultRows,
                    'showImportResult' => true,
                ])
            </div>
        @endif
    </div>
</x-filament-panels::page>
