@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];
@endphp

@if (count($steps) === 0)
    <p class="m-0 text-sm text-gray-500">No timeline available.</p>
@else
    <ol class="m-0 flex list-none flex-col gap-0 p-0">
        @foreach ($steps as $index => $step)
            @php
                $done = ! empty($step['completed']);
                $current = ! empty($step['is_current']);
                $rejected = ! empty($step['is_rejection']);
                $pending = ! empty($step['pending']) || (! $done && ! $rejected && ! $current);
                $label = (string) ($step['label'] ?? '');
                $actor = trim((string) ($step['actor'] ?? ''));
                $role = trim((string) ($step['actor_role'] ?? ''));
                $at = trim((string) ($step['at'] ?? ''));
                $remark = trim((string) ($step['remark'] ?? ''));
                $isLast = $loop->last;

                $dotClass = $rejected
                    ? 'bg-danger-600 text-white'
                    : ($done
                        ? 'bg-success-600 text-white'
                        : ($current ? 'bg-warning-500 text-white' : 'bg-gray-300 text-gray-700'));

                $titleClass = $rejected
                    ? 'text-danger-700 dark:text-danger-400'
                    : ($done ? 'text-gray-950 dark:text-white' : 'text-gray-500');
            @endphp
            <li class="relative flex gap-3 {{ $isLast ? '' : 'pb-4' }}">
                @if (! $isLast)
                    <span class="absolute bottom-0 left-[0.6875rem] top-5 w-px {{ $done && ! $rejected ? 'bg-success-300' : 'bg-gray-200' }}" aria-hidden="true"></span>
                @endif
                <span class="relative z-10 mt-0.5 inline-flex shrink-0 items-center justify-center rounded-full text-[0.65rem] font-bold {{ $dotClass }}" style="width:1.375rem;height:1.375rem;">
                    @if ($rejected)
                        !
                    @elseif ($done)
                        ✓
                    @else
                        {{ $index + 1 }}
                    @endif
                </span>
                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="text-sm font-semibold leading-5 {{ $titleClass }}">{{ $label }}</div>
                    @if ($actor !== '' || $role !== '')
                        <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">
                            {{ $actor }}@if ($actor !== '' && $role !== '') · @endif@if ($role !== ''){{ $role }}@endif
                        </div>
                    @endif
                    @if ($at !== '')
                        <div class="mt-0.5 text-xs text-gray-500">{{ $at }}</div>
                    @elseif ($pending || $current)
                        <div class="mt-0.5 text-xs text-gray-400">Pending</div>
                    @endif
                    @if ($remark !== '' && $rejected)
                        <div class="mt-1 rounded-md bg-danger-50 px-2 py-1.5 text-xs text-danger-700 dark:bg-danger-950 dark:text-danger-300">{{ $remark }}</div>
                    @elseif ($remark !== '')
                        <div class="mt-0.5 text-xs text-gray-500">{{ $remark }}</div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
