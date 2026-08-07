<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">
                {{ $this->getImportHeading() }}
            </h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                {{ $this->getImportDescription() }}
            </p>

            <div class="mt-5 flex flex-wrap gap-3">
                <x-filament::button color="gray" wire:click="downloadTemplate">
                    Download Excel Template
                </x-filament::button>

                <x-filament::button wire:click="startImport">
                    Import Excel
                </x-filament::button>
            </div>
        </div>

        @if ($phase === 'upload')
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Upload Excel</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Select your filled Excel file. Data is not saved until you confirm the import.
                </p>

                <form wire:submit="previewUpload" class="mt-5 max-w-2xl space-y-4">
                    {{ $this->form }}

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,file">
                            <span wire:loading.remove wire:target="previewUpload,file">Validate &amp; Preview</span>
                            <span wire:loading wire:target="previewUpload,file">Validating...</span>
                        </x-filament::button>

                        <x-filament::button color="gray" type="button" wire:click="cancelImport">
                            Cancel
                        </x-filament::button>
                    </div>
                </form>
            </div>
        @endif

        @if ($phase === 'preview')
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Preview &amp; Validate</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Nothing is saved yet. Review each row, then confirm to import valid rows only.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="gray" wire:click="startImport" wire:loading.attr="disabled" wire:target="runImport">
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
                    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            'total' => 'Total Rows',
                            'valid' => 'Valid',
                            'invalid' => 'Invalid',
                            'to_import' => 'Will Import',
                        ] as $key => $label)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $previewCounts[$key] ?? 0 }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div wire:loading wire:target="runImport" class="mt-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:border-primary-500/30 dark:bg-primary-950 dark:text-primary-300">
                    Import in progress — please wait.
                </div>

                <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-left">
                                <th class="px-4 py-3">Row</th>
                                <th class="px-4 py-3">{{ $this->getResultNameLabel() }}</th>
                                <th class="px-4 py-3">Unit</th>
                                <th class="px-4 py-3">Opening Quantity</th>
                                <th class="px-4 py-3">Opening Value</th>
                                <th class="px-4 py-3">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($previewRows as $row)
                                @php $data = $row['data'] ?? []; @endphp
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $row['row_number'] }}</td>
                                    <td class="px-4 py-3">{{ $data[$this->getPreviewNameField()] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $data['unit'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $data['opening_quantity'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $data['opening_value'] ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($row['is_valid'])
                                            <span class="inline-flex rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                                Valid
                                            </span>
                                        @else
                                            <span class="inline-flex rounded-full bg-danger-50 px-2.5 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                                {{ $row['error'] }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                        No data rows found in the uploaded file.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (($previewCounts['total'] ?? 0) > count($previewRows))
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Showing a sample of {{ count($previewRows) }} rows. All {{ $previewCounts['total'] }} rows were validated and will be processed on confirm.
                    </p>
                @endif
            </div>
        @endif

        @if ($phase === 'result' && $summary !== null)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">Import Result</h3>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        @if ($failedRows !== [])
                            <x-filament::button color="danger" wire:click="downloadErrorReport">
                                Download Failed Rows
                            </x-filament::button>
                        @endif

                        <x-filament::button color="gray" wire:click="cancelImport">
                            Done
                        </x-filament::button>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Total Rows</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total_rows'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Successfully Imported</div>
                        <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $summary['imported'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Failed Rows</div>
                        <div class="mt-2 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $summary['failed'] }}</div>
                    </div>
                </div>

                @if ($importedRows !== [])
                    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr class="text-left">
                                    <th class="px-4 py-3">{{ $this->getResultNameLabel() }}</th>
                                    <th class="px-4 py-3">{{ $this->getResultCodeLabel() }}</th>
                                    <th class="px-4 py-3">Opening Quantity</th>
                                    <th class="px-4 py-3">Opening Value</th>
                                    <th class="px-4 py-3">Import Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($importedRows as $row)
                                    <tr>
                                        <td class="px-4 py-3">{{ $row['material_name'] ?: '-' }}</td>
                                        <td class="px-4 py-3 font-medium">{{ $row['material_code'] ?: '-' }}</td>
                                        <td class="px-4 py-3">{{ $row['opening_quantity'] }}</td>
                                        <td class="px-4 py-3">{{ $row['opening_value'] }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                                {{ $row['status'] ?? 'Success' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($failedRows !== [])
                    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr class="text-left">
                                    <th class="px-4 py-3">Row</th>
                                    <th class="px-4 py-3">{{ $this->getResultNameLabel() }}</th>
                                    <th class="px-4 py-3">Error</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach (array_slice($failedRows, 0, 100) as $error)
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $error['row_number'] }}</td>
                                        <td class="px-4 py-3">{{ $error['data'][$this->getPreviewNameField()] ?? '-' }}</td>
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
