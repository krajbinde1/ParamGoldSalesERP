<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Import in sequence. Master templates do <strong>not</strong> include Material Code — codes are auto-generated
                (RM / PK / SFM / FP). After each successful master import, download the Code Mapping Excel and use those codes in BOM.
                Finished Product Import links existing Products 1:1 and never creates sales products.
            </p>
        </div>

        <div class="grid gap-3 lg:grid-cols-5">
            @foreach ($this->sequenceSteps() as $step)
                <button
                    type="button"
                    wire:click="selectModule('{{ $step['type'] }}')"
                    @class([
                        'rounded-xl border px-4 py-3 text-left transition',
                        'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-950' => $importType === $step['type'],
                        'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-950/40' => $step['blocked'] && $importType !== $step['type'],
                        'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $importType !== $step['type'] && ! $step['blocked'],
                        'opacity-80' => $step['blocked'],
                    ])
                >
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Step {{ $step['step'] }}
                    </div>
                    <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $step['label'] }}
                    </div>
                    <div class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                        Status: {{ $this->statusLabel($step['status']) }}
                    </div>
                    @if (($step['imported'] + $step['failed']) > 0)
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Imported {{ $step['imported'] }} · Failed {{ $step['failed'] }}
                        </div>
                    @endif
                    @if ($step['blocked'])
                        <div class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                            Blocked until masters + FG exist
                        </div>
                    @endif
                </button>
            @endforeach
        </div>

        @php
            $activeStep = collect($this->sequenceSteps())->firstWhere('type', $importType);
        @endphp

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->moduleLabel() }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        Recommended sequence: Raw Material → Packaging → Semi-Finished → Finished Product → BOM.
                    </p>
                    @if ($activeStep['blocked'] ?? false)
                        <p class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                            {{ $activeStep['block_reason'] }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-filament::button color="gray" wire:click="downloadTemplateFor('{{ $importType }}')">
                        Download Template
                    </x-filament::button>
                    <x-filament::button color="gray" wire:click="downloadCodeMappingFor('{{ $importType }}')">
                        Download Code Mapping
                    </x-filament::button>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([1 => 'Upload Excel', 2 => 'Preview & Confirm', 3 => 'Import Summary'] as $stepNumber => $stepLabel)
                <div @class([
                    'rounded-xl border px-4 py-3',
                    'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300' => $wizardStep === $stepNumber,
                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $wizardStep !== $stepNumber,
                ])>
                    <div class="text-xs font-semibold uppercase tracking-wide">Phase {{ $stepNumber }}</div>
                    <div class="mt-1 text-sm font-medium">{{ $stepLabel }}</div>
                </div>
            @endforeach
        </div>

        @if ($wizardStep === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="max-w-2xl space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Upload {{ $this->moduleLabel() }} File</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Download the Excel template, fill your data (no material code column for masters), then upload for validation.
                            Nothing is saved until you confirm import.
                        </p>
                    </div>

                    @if (! ($activeStep['blocked'] ?? false))
                        <form wire:submit="previewUpload" class="space-y-4">
                            {{ $this->form }}

                            <div class="flex flex-wrap items-center gap-3">
                                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,file">
                                    <span wire:loading.remove wire:target="previewUpload,file">Validate &amp; Preview</span>
                                    <span wire:loading wire:target="previewUpload,file">Validating...</span>
                                </x-filament::button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @if ($wizardStep === 2)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Preview — {{ $this->moduleLabel() }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Review validation results. Confirm import only saves valid rows. Invalid / duplicate rows are skipped.
                            Name mismatches on BOM rows show as warnings; matching uses codes only.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="resetUpload" wire:loading.attr="disabled" wire:target="runImport">
                            Upload Another File
                        </x-filament::button>

                        <x-filament::button
                            wire:click="runImport"
                            wire:loading.attr="disabled"
                            wire:target="runImport"
                            :disabled="($previewCounts['to_import'] ?? 0) < 1"
                        >
                            <span wire:loading.remove wire:target="runImport">Confirm Import</span>
                            <span wire:loading wire:target="runImport">Importing...</span>
                        </x-filament::button>
                    </div>
                </div>

                @if ($previewCounts !== null)
                    <div class="mt-6 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        @foreach ([
                            'total' => 'Total',
                            'valid' => 'Valid',
                            'invalid' => 'Invalid',
                            'duplicate' => 'Duplicate',
                            'to_import' => 'To Import',
                            'to_skip' => 'To Skip',
                        ] as $key => $label)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $previewCounts[$key] ?? 0 }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div wire:loading wire:target="runImport" class="mt-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:border-primary-500/30 dark:bg-primary-950 dark:text-primary-300">
                    Import in progress. Large files are processed in chunks — please wait.
                </div>

                <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-left">
                                <th class="px-4 py-3">Row</th>
                                <th class="px-4 py-3">Key Fields</th>
                                <th class="px-4 py-3">Action</th>
                                <th class="px-4 py-3">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($previewRows as $row)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $row['row_number'] }}</td>
                                    <td class="px-4 py-3">
                                        @php
                                            $data = $row['data'] ?? [];
                                            $parts = array_filter([
                                                $data['material_name'] ?? null,
                                                $data['material_code'] ?? null,
                                                $data['existing_product'] ?? null,
                                                $data['finished_product_code'] ?? $data['finished_product'] ?? null,
                                                isset($data['material_type']) ? 'Type: '.$data['material_type'] : null,
                                                isset($data['unit']) ? 'Unit: '.$data['unit'] : null,
                                                isset($data['opening_quantity']) && $data['opening_quantity'] !== '' ? 'Open Qty: '.$data['opening_quantity'] : null,
                                                isset($data['quantity']) && $data['quantity'] !== '' ? 'Qty: '.$data['quantity'] : null,
                                            ]);
                                        @endphp
                                        {{ implode(' · ', $parts) ?: '-' }}
                                    </td>
                                    <td class="px-4 py-3 capitalize">{{ $row['action'] ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($row['is_valid'])
                                            <span class="inline-flex rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                                Valid
                                            </span>
                                            @if (! empty($row['warning']))
                                                <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $row['warning'] }}</div>
                                            @endif
                                        @else
                                            <span class="inline-flex rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                                {{ $row['error'] }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                        No data rows found in the uploaded file.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (($previewCounts['total'] ?? 0) > count($previewRows))
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Showing a sample of {{ count($previewRows) }} rows for preview. All {{ $previewCounts['total'] }} rows were validated and will be processed on confirm.
                    </p>
                @endif
            </div>
        @endif

        @if ($wizardStep === 3 && $summary !== null)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Import Summary — {{ $this->moduleLabel() }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Opening stock (when quantity &gt; 0) posts Opening Stock ledger entries and updates current stock / stock value / average rate. Qty 0 creates master only.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        @if ($importType !== 'bom' && ($summary['imported'] ?? 0) > 0)
                            <x-filament::button color="success" wire:click="downloadLastImportMapping">
                                Download Code Mapping
                            </x-filament::button>
                        @endif

                        @if ($failedRows !== [])
                            <x-filament::button color="danger" wire:click="downloadErrorReport">
                                Download Failed Rows Report
                            </x-filament::button>
                        @endif

                        <x-filament::button wire:click="resetUpload">
                            Import Another File
                        </x-filament::button>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Imported</div>
                        <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $summary['imported'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Skipped / Failed</div>
                        <div class="mt-2 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $summary['failed'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Opening Ledger Created</div>
                        <div class="mt-2 text-2xl font-semibold text-primary-600 dark:text-primary-400">{{ $summary['opening_ledger_created'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Current Stock / Value / Avg Rate Updated</div>
                        <div class="mt-2 text-2xl font-semibold text-primary-600 dark:text-primary-400">{{ $summary['stock_updated'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Total Rows</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total_rows'] }}</div>
                    </div>
                </div>

                @if ($failedRows !== [])
                    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr class="text-left">
                                    <th class="px-4 py-3">Row</th>
                                    <th class="px-4 py-3">Details</th>
                                    <th class="px-4 py-3">Error Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach (array_slice($failedRows, 0, 100) as $error)
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $error['row_number'] }}</td>
                                        <td class="px-4 py-3">
                                            {{ $error['data']['material_name']
                                                ?? $error['data']['material_code']
                                                ?? $error['data']['existing_product']
                                                ?? $error['data']['finished_product_code']
                                                ?? $error['data']['finished_product']
                                                ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-danger-600 dark:text-danger-400">{{ $error['reason'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
