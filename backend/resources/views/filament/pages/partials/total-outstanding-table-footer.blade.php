@php
    /** @var string $total */
    $span = isset($columns) && is_countable($columns) && count($columns) > 0
        ? count($columns) + 1
        : (int) ($columnCount ?? 1);
    $showCredit = (bool) ($showCredit ?? false);
@endphp

<td colspan="{{ $span }}" class="!p-0">
    <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2 px-4 py-3 text-sm">
        <div class="flex items-center gap-3">
            <span class="font-semibold text-gray-700 dark:text-gray-200">Total Outstanding</span>
            <span class="font-semibold tabular-nums text-danger-600 dark:text-danger-400">
                {{ $total }}
            </span>
        </div>
        @if ($showCredit)
            <div class="flex items-center gap-3">
                <span class="font-semibold text-gray-700 dark:text-gray-200">Total Credit Balance</span>
                <span class="font-semibold tabular-nums text-success-600 dark:text-success-400">
                    {{ $credit }}
                </span>
            </div>
            <div class="flex items-center gap-3">
                <span class="font-semibold text-gray-700 dark:text-gray-200">Net Balance</span>
                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ $net }}
                </span>
            </div>
        @endif
    </div>
</td>
