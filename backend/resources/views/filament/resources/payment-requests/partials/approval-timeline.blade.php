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

                $state = $rejected ? 'rejected' : ($done ? 'done' : ($pending ? 'pending' : 'idle'));

                $dotClass = match ($state) {
                    'rejected' => 'border-red-500 bg-red-500 text-white dark:border-red-400 dark:bg-red-500',
                    'done' => 'border-teal-500 bg-teal-500 text-white dark:border-teal-400 dark:bg-teal-500',
                    'pending' => 'border-amber-500 bg-amber-500 text-white dark:border-amber-400 dark:bg-amber-500',
                    default => 'border-gray-300 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-500',
                };

                $lineClass = match ($state) {
                    'done' => 'bg-teal-400 dark:bg-teal-500',
                    'pending' => 'bg-amber-300 dark:bg-amber-500/70',
                    'rejected' => 'bg-red-300 dark:bg-red-500/70',
                    default => 'bg-gray-200 dark:bg-gray-700',
                };

                $badgeClass = match ($state) {
                    'rejected' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-400/30',
                    'done' => 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-950/50 dark:text-teal-300 dark:ring-teal-400/30',
                    'pending' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-400/30',
                    default => 'bg-gray-50 text-gray-500 ring-gray-500/15 dark:bg-gray-800 dark:text-gray-400 dark:ring-white/10',
                };

                $titleClass = match ($state) {
                    'rejected' => 'text-red-700 dark:text-red-300',
                    'idle' => 'text-gray-400 dark:text-gray-500',
                    default => 'text-gray-900 dark:text-gray-100',
                };
            @endphp
            <li class="relative flex gap-4 {{ $isLast ? '' : 'pb-6' }}">
                <div class="relative flex w-8 shrink-0 flex-col items-center">
                    <span
                        class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-2 text-xs font-bold shadow-sm {{ $dotClass }}"
                        aria-hidden="true"
                    >
                        @if ($rejected)
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        @elseif ($done)
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        @elseif ($pending)
                            <span class="h-2 w-2 rounded-full bg-white"></span>
                        @else
                            <span class="h-2 w-2 rounded-full bg-current opacity-40"></span>
                        @endif
                    </span>
                    @unless ($isLast)
                        <span class="absolute top-8 bottom-0 w-0.5 {{ $lineClass }}" aria-hidden="true"></span>
                    @endunless
                </div>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="text-sm font-semibold leading-5 {{ $titleClass }}">{{ $label }}</h4>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide ring-1 ring-inset {{ $badgeClass }}">
                            {{ $badge }}
                        </span>
                    </div>

                    @if ($actor !== '' || $role !== '')
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            @if ($actor !== '' && $role !== '')
                                {{ $actor }} <span class="text-gray-400 dark:text-gray-500">•</span> {{ $role }}
                            @elseif ($actor !== '')
                                {{ $actor }}
                            @else
                                {{ $role }}
                            @endif
                        </p>
                    @elseif ($pending)
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">Awaiting action</p>
                    @elseif ($notStarted)
                        <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">Not started</p>
                    @endif

                    @if ($at !== '')
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $at }}</p>
                    @endif

                    @if ($remark !== '')
                        <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300">
                            <span class="font-medium text-gray-500 dark:text-gray-400">Remark:</span>
                            {{ $remark }}
                        </p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
