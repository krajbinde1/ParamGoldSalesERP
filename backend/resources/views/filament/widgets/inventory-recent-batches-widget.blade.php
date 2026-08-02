<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Recent Production Batches
        </x-slot>

        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr class="text-left">
                        <th class="px-3 py-2">Batch</th>
                        <th class="px-3 py-2">Product</th>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Output</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Posted By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @php
                        $statusColors = [
                            'gray' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
                            'info' => 'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400',
                            'success' => 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
                            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400',
                        ];
                    @endphp
                    @forelse ($batches as $batch)
                        <tr>
                            <td class="px-3 py-2">
                                <a href="{{ \App\Filament\Resources\ProductionBatches\ProductionBatchResource::getUrl('view', ['record' => $batch]) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $batch->batch_number }}
                                </a>
                            </td>
                            <td class="px-3 py-2">{{ $batch->product?->product_name }}</td>
                            <td class="px-3 py-2">{{ $batch->production_date?->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $batch->actual_output_quantity, 3) }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColors[$batch->status->color()] ?? $statusColors['gray'] }}">
                                    {{ $batch->status->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2">{{ $batch->supervisor?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-4 text-center text-gray-500">No production batches recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
