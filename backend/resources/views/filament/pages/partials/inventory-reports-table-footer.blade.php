@php
    /** @var float|null $total */
@endphp

@if ($total !== null)
    <td colspan="{{ max(count($columns ?? []), 1) }}" class="inventory-reports-table-footer !p-0">
        <div class="flex items-center justify-end gap-3 px-4 py-3 text-sm">
            <span class="font-semibold text-gray-700 dark:text-gray-200">Total Value</span>
            <span class="font-semibold tabular-nums text-gray-950 dark:text-white">
                ₹{{ number_format((float) $total, 2) }}
            </span>
        </div>
    </td>
@endif
