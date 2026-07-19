<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-3">
            <div @class([
                'rounded-xl border px-4 py-3',
                'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300' => $step === 1,
                'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $step !== 1,
            ])>
                <div class="text-xs font-semibold uppercase tracking-wide">Step 1</div>
                <div class="mt-1 text-sm font-medium">Upload File</div>
            </div>
            <div @class([
                'rounded-xl border px-4 py-3',
                'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300' => $step === 2,
                'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $step !== 2,
            ])>
                <div class="text-xs font-semibold uppercase tracking-wide">Step 2</div>
                <div class="mt-1 text-sm font-medium">Preview Data</div>
            </div>
            <div @class([
                'rounded-xl border px-4 py-3',
                'border-primary-500 bg-primary-50 text-primary-700 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-300' => $step === 3,
                'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' => $step !== 3,
            ])>
                <div class="text-xs font-semibold uppercase tracking-wide">Step 3</div>
                <div class="mt-1 text-sm font-medium">Import Summary</div>
            </div>
        </div>

        @if ($step === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="max-w-2xl space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Upload Product File</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Use the Excel template with mandatory and optional columns. Product Name, Dealer Price, Nos Per Case, GST %, and Status are required.
                        </p>
                    </div>

                    <form wire:submit="previewUpload" class="space-y-4">
                        {{ $this->form }}

                        <div class="flex flex-wrap items-center gap-3">
                            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewUpload,file">
                                <span wire:loading.remove wire:target="previewUpload,file">Preview Upload</span>
                                <span wire:loading wire:target="previewUpload,file">Processing file...</span>
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if ($step === 2)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Preview Uploaded Products</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Review the parsed rows before importing. Invalid rows will be skipped during import.
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
                        >
                            <span wire:loading.remove wire:target="runImport">Import Products</span>
                            <span wire:loading wire:target="runImport">Importing products...</span>
                        </x-filament::button>
                    </div>
                </div>

                <div wire:loading wire:target="runImport" class="mt-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-700 dark:border-primary-500/30 dark:bg-primary-950 dark:text-primary-300">
                    Import in progress. Please wait while products are created or updated.
                </div>

                <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-left">
                                <th class="px-4 py-3">Row</th>
                                <th class="px-4 py-3">Product Name</th>
                                <th class="px-4 py-3">Product Code</th>
                                <th class="px-4 py-3">Dealer Price</th>
                                <th class="px-4 py-3">Nos Per Case</th>
                                <th class="px-4 py-3">GST %</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Action</th>
                                <th class="px-4 py-3">Validation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($previewRows as $row)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $row['row_number'] }}</td>
                                    <td class="px-4 py-3">{{ $row['data']['product_name'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ filled($row['data']['product_code'] ?? null) ? $row['data']['product_code'] : 'Auto-generate' }}</td>
                                    <td class="px-4 py-3">{{ $row['data']['dealer_price'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $row['data']['nos_per_case'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $row['data']['gst_percentage'] ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $row['data']['status'] ?? '-' }}</td>
                                    <td class="px-4 py-3 capitalize">{{ $row['action'] ?? '-' }}</td>
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
                                    <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                                        No product rows found in the uploaded file.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($step === 3 && $summary !== null)
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">Import Summary</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Bulk product upload completed. Valid rows were imported and invalid rows were skipped.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        @if ($failedRows !== [])
                            <x-filament::button color="danger" wire:click="downloadErrorReport">
                                Download Error Report
                            </x-filament::button>
                        @endif

                        <x-filament::button wire:click="resetUpload">
                            Upload Another File
                        </x-filament::button>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Total Rows</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $summary['total_rows'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Successfully Created</div>
                        <div class="mt-2 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $summary['created'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Successfully Updated</div>
                        <div class="mt-2 text-2xl font-semibold text-primary-600 dark:text-primary-400">{{ $summary['updated'] }}</div>
                    </div>
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Failed Rows</div>
                        <div class="mt-2 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $summary['failed'] }}</div>
                    </div>
                </div>

                @if ($failedRows !== [])
                    <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr class="text-left">
                                    <th class="px-4 py-3">Row</th>
                                    <th class="px-4 py-3">Product Name</th>
                                    <th class="px-4 py-3">Product Code</th>
                                    <th class="px-4 py-3">Error</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($failedRows as $error)
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $error->rowNumber }}</td>
                                        <td class="px-4 py-3">{{ $error->rowData['product_name'] ?? '-' }}</td>
                                        <td class="px-4 py-3">{{ $error->rowData['product_code'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-danger-600 dark:text-danger-400">{{ $error->reason }}</td>
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
