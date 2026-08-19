@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];
@endphp

@if (count($steps) === 0)
    <p class="m-0 text-sm text-gray-500 dark:text-gray-400">No timeline available.</p>
@else
    <ol class="m-0 flex list-none flex-col gap-0 p-0">
        @foreach ($steps as $step)
            @php
                $done = ! empty($step['completed']);
                $current = ! empty($step['is_current']);
                $rejected = ! empty($step['is_rejection']);
                $notStarted = ! empty($step['not_started']);
                $pending = ! empty($step['pending']) || $current;
                $label = (string) ($step['label'] ?? '');
                $badge = trim((string) ($step['badge'] ?? ''));
                $actor = trim((string) ($step['actor'] ?? ''));
                $role = trim((string) ($step['actor_role'] ?? ''));
                $at = trim((string) ($step['at'] ?? ''));
                $remark = trim((string) ($step['remark'] ?? ''));
                $isLast = $loop->last;

                if ($badge === '') {
                    $badge = $rejected
                        ? 'Rejected'
                        : ($done ? 'Completed' : ($pending ? 'Pending' : 'Not Started'));
                }

                $dotClass = $rejected
                    ? 'bg-danger-600 text-white ring-danger-100'
                    : ($done
                        ? 'bg-success-600 text-white ring-success-100'
                        : ($pending
                            ? 'bg-warning-500 text-white ring-warning-100'
                            : 'bg-gray-200 text-gray-500 ring-gray-100 dark:bg-gray-700 dark:text-gray-300'));

                $lineClass = $done && ! $rejected
                    ? 'bg-success-300 dark:bg-success-700'
                    : 'bg-gray-200 dark:bg-white/10';

                $titleClass = $rejected
                    ? 'text-danger-700 dark:text-danger-400'
                    : ($done || $pending
                        ? 'text-gray-950 dark:text-white'
                        : 'text-gray-400 dark:text-gray-500');

                $badgeClass = $rejected
                    ? 'bg-danger-50 text-danger-700 ring-danger-600/15 dark:bg-danger-950 dark:text-danger-300'
                    : ($done
                        ? 'bg-success-50 text-success-700 ring-success-600/15 dark:bg-success-950 dark:text-success-300'
                        : ($pending
                            ? 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-950 dark:text-warning-300'
                            : 'bg-gray-50 text-gray-500 ring-gray-500/10 dark:bg-gray-800 dark:text-gray-400'));
            @endphp
            <li class="relative flex gap-3.5 {{ $isLast ? '' : 'pb-5' }}">
                @if (! $isLast)
                    <span class="absolute bottom-0 left-[0.8125rem] top-7 w-0.5 {{ $lineClass }}" aria-hidden="true"></span>
                @endif

                <span
                    class="relative z-10 mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 {{ $dotClass }}"
                    aria-hidden="true"
                >
                    @if ($rejected)
                        ✕
                    @elseif ($done)
                        ✓
                    @elseif ($pending)
                        ●
                    @else
                        ○
                    @endif
                </span>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="text-sm font-semibold leading-5 {{ $titleClass }}">{{ $label }}</div>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $badgeClass }}">
                            {{ $badge }}
                        </span>
                    </div>

                    @if ($actor !== '' || $role !== '')
                        <div class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                            @if ($actor !== '')
                                <span class="font-medium">{{ $actor }}</span>
                            @endif
                            @if ($role !== '')
                                <span class="text-gray-500 dark:text-gray-400">{{ $actor !== '' ? ' · '.$role : $role }}</span>
                            @endif
                        </div>
                    @elseif ($notStarted)
                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">Not started</div>
                    @endif

                    @if ($at !== '')
                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $at }}</div>
                    @endif

                    @if ($remark !== '' && $rejected)
                        <div class="mt-2 rounded-lg bg-danger-50 px-2.5 py-1.5 text-xs text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                            {{ $remark }}
                        </div>
                    @elseif ($remark !== '')
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $remark }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
