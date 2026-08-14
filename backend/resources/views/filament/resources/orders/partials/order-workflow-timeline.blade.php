@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];

    $displayLabels = [
        'created' => 'Order Placed',
        'approved' => 'Manager Approved',
        'pending_for_billing' => 'Sent for Bill',
        'billed' => 'Billed',
        'dispatched' => 'Dispatched',
    ];
@endphp

@if (count($steps) === 0)
    <p class="m-0 text-sm text-gray-500 dark:text-gray-400">No workflow steps available.</p>
@else
    <ol class="m-0 flex list-none flex-col gap-0 p-0">
        @foreach ($steps as $index => $step)
            @php
                $done = ! empty($step['completed']);
                $current = ! empty($step['is_current']);
                $rejected = ! empty($step['is_rejection']);
                $key = (string) ($step['key'] ?? '');
                $label = $displayLabels[$key] ?? (string) ($step['label'] ?? '');
                if ($rejected) {
                    $label = (string) ($step['label'] ?? 'Rejected');
                }
                $actor = trim((string) ($step['actor'] ?? ''));
                $role = trim((string) ($step['actor_role'] ?? ''));
                $at = trim((string) ($step['at'] ?? ''));
                $statusText = trim((string) ($step['status_text'] ?? ''));
                $remark = trim((string) ($step['remark'] ?? ''));
                $isLast = $loop->last;

                $dotClass = $rejected
                    ? 'bg-danger-600 text-white ring-danger-200 dark:ring-danger-900'
                    : ($done
                        ? 'bg-success-600 text-white ring-success-200 dark:ring-success-900'
                        : ($current
                            ? 'bg-warning-500 text-white ring-warning-200 dark:ring-warning-900'
                            : 'bg-gray-300 text-gray-600 ring-gray-200 dark:bg-gray-600 dark:text-gray-200 dark:ring-gray-700'));

                $titleClass = $rejected
                    ? 'text-danger-700 dark:text-danger-400'
                    : ($done
                        ? 'text-gray-950 dark:text-white'
                        : ($current
                            ? 'text-warning-700 dark:text-warning-400'
                            : 'text-gray-400 dark:text-gray-500'));
            @endphp

            <li class="relative flex gap-3 {{ $isLast ? '' : 'pb-4' }}">
                @if (! $isLast)
                    <span
                        class="absolute bottom-0 left-[0.6875rem] top-5 w-px {{ $done && ! $rejected ? 'bg-success-300 dark:bg-success-800' : 'bg-gray-200 dark:bg-gray-700' }}"
                        aria-hidden="true"
                    ></span>
                @endif

                <span
                    class="relative z-10 mt-0.5 inline-flex h-5.5 w-5.5 shrink-0 items-center justify-center rounded-full text-[0.65rem] font-bold ring-2 {{ $dotClass }}"
                    style="width:1.375rem;height:1.375rem;"
                >
                    @if ($rejected)
                        !
                    @elseif ($done)
                        ✓
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="text-sm font-semibold leading-5 {{ $titleClass }}">
                        {{ $label }}
                    </div>

                    @if ($actor !== '' || $role !== '')
                        <div class="mt-0.5 text-xs leading-4 text-gray-600 dark:text-gray-300">
                            @if ($actor !== '')
                                {{ $actor }}
                            @endif
                            @if ($actor !== '' && $role !== '')
                                <span class="text-gray-400 dark:text-gray-500"> · </span>
                            @endif
                            @if ($role !== '')
                                <span class="text-gray-500 dark:text-gray-400">{{ $role }}</span>
                            @endif
                        </div>
                    @endif

                    @if ($at !== '')
                        <div class="mt-0.5 text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $at }}</div>
                    @elseif (! $done)
                        <div class="mt-0.5 text-xs leading-4 text-gray-400 dark:text-gray-500">Pending</div>
                    @endif

                    @if ($statusText !== '' && $done)
                        <div class="mt-0.5 text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $statusText }}</div>
                    @endif

                    @if ($remark !== '' && $rejected)
                        <div class="mt-1 rounded-md bg-danger-50 px-2 py-1.5 text-xs leading-4 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                            {{ $remark }}
                        </div>
                    @elseif ($remark !== '')
                        <div class="mt-0.5 text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $remark }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
