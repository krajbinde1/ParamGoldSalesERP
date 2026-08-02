<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Low Stock Alerts
        </x-slot>

        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Raw Materials</h3>
                <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-left">
                                <th class="px-3 py-2">Material</th>
                                <th class="px-3 py-2">Stock</th>
                                <th class="px-3 py-2">Minimum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($rawMaterials as $material)
                                <tr>
                                    <td class="px-3 py-2">{{ $material->material_name }}</td>
                                    <td class="px-3 py-2 text-warning-600 dark:text-warning-400">{{ number_format((float) $material->current_stock, 3) }} {{ $material->unit }}</td>
                                    <td class="px-3 py-2">{{ number_format((float) $material->minimum_stock, 3) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-4 text-center text-gray-500">No raw materials are low on stock.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Packaging Materials</h3>
                <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr class="text-left">
                                <th class="px-3 py-2">Material</th>
                                <th class="px-3 py-2">Stock</th>
                                <th class="px-3 py-2">Minimum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($packagingMaterials as $material)
                                <tr>
                                    <td class="px-3 py-2">{{ $material->packaging_name }}</td>
                                    <td class="px-3 py-2 text-warning-600 dark:text-warning-400">{{ number_format((float) $material->current_stock, 3) }} {{ $material->unit }}</td>
                                    <td class="px-3 py-2">{{ number_format((float) $material->minimum_stock, 3) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-3 py-4 text-center text-gray-500">No packaging materials are low on stock.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
