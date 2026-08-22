@php
    /** @var string $total */
    $span = isset($columns) && is_countable($columns) && count($columns) > 0
        ? count($columns) + 1
        : (int) ($columnCount ?? 1);
@endphp

<td colspan="{{ $span }}" class="!p-0">
    <div class="flex items-center justify-end gap-3 px-4 py-3 text-sm">
        <span class="font-semibold text-gray-700 dark:text-gray-200">Total Outstanding</span>
        <span class="font-semibold tabular-nums text-danger-600 dark:text-danger-400">
            {{ $total }}
        </span>
    </div>
</td>
