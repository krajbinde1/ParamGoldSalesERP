@php
    $salesPercentage = min((float) ($performance['sales_percentage'] ?? 0), 100);
    $collectionPercentage = min((float) ($performance['collection_percentage'] ?? 0), 100);
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Employee Details
            </x-slot>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Name</p>
                    <p class="font-medium text-gray-950 dark:text-white">{{ $employee->full_name }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Employee Code</p>
                    <p class="font-medium text-gray-950 dark:text-white">{{ $employee->employee_code ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Mobile Number</p>
                    <p class="font-medium text-gray-950 dark:text-white">{{ $employee->mobile ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Role</p>
                    <p class="font-medium text-gray-950 dark:text-white">{{ $performance['role_label'] ?? 'Employee' }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Target and Achievement
            </x-slot>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Sales Target</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatCurrency((float) $performance['sales_target']) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Sales Achievement</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatCurrency((float) $performance['sales_achieved']) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Sales Percentage</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatPercentage((float) $performance['sales_percentage']) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-2 rounded-full bg-success-500" style="width: {{ $salesPercentage }}%"></div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Collection Target</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatCurrency((float) $performance['collection_target']) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Collection Achievement</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatCurrency((float) $performance['collection_achieved']) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Collection Percentage</span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $this->formatPercentage((float) $performance['collection_percentage']) }}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-2 rounded-full bg-info-500" style="width: {{ $collectionPercentage }}%"></div>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <div class="overflow-x-auto">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
