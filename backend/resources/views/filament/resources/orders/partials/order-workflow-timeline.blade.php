@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];
@endphp

@if (count($steps) === 0)
    <p style="margin:0;color:#64748b;font-size:0.875rem;">No workflow steps available.</p>
@else
    <div style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:stretch;">
        @foreach ($steps as $index => $step)
            @php
                $done = ! empty($step['completed']);
                $current = ! empty($step['is_current']);
                $rejected = ! empty($step['is_rejection']);
                $border = $rejected
                    ? '#fca5a5'
                    : ($done ? '#86efac' : ($current ? '#fcd34d' : '#e2e8f0'));
                $bg = $rejected
                    ? '#fef2f2'
                    : ($done ? '#f0fdf4' : ($current ? '#fffbeb' : '#f8fafc'));
                $titleColor = $rejected
                    ? '#b91c1c'
                    : ($done ? '#166534' : ($current ? '#92400e' : '#64748b'));
                $label = (string) ($step['label'] ?? '');
                $actor = trim((string) ($step['actor'] ?? ''));
                $role = trim((string) ($step['actor_role'] ?? ''));
                $at = trim((string) ($step['at'] ?? ''));
                $statusText = trim((string) ($step['status_text'] ?? ''));
                $remark = trim((string) ($step['remark'] ?? ''));
            @endphp

            <div style="flex:1 1 160px;min-width:150px;max-width:240px;border:1px solid {{ $border }};background:{{ $bg }};border-radius:0.75rem;padding:0.75rem 0.875rem;">
                <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.35rem;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:1.25rem;height:1.25rem;border-radius:999px;font-size:0.7rem;font-weight:700;color:#fff;background:{{ $rejected ? '#ef4444' : ($done ? '#22c55e' : ($current ? '#f59e0b' : '#94a3b8')) }};">
                        {{ $rejected ? '!' : ($done ? '✓' : ($index + 1)) }}
                    </span>
                    <span style="font-size:0.8125rem;font-weight:700;color:{{ $titleColor }};line-height:1.25;">
                        {{ $label }}
                    </span>
                </div>

                @if ($actor !== '')
                    <div style="font-size:0.75rem;color:#334155;line-height:1.35;">
                        {{ $actor }}@if ($role !== '') <span style="color:#64748b;">• {{ $role }}</span>@endif
                    </div>
                @endif

                @if ($at !== '')
                    <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">{{ $at }}</div>
                @endif

                @if ($statusText !== '')
                    <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">{{ $statusText }}</div>
                @elseif (! $done)
                    <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.2rem;">Pending</div>
                @endif

                @if ($remark !== '')
                    <div style="font-size:0.75rem;color:#64748b;margin-top:0.35rem;">{{ $remark }}</div>
                @endif
            </div>

            @if (! $loop->last)
                <div style="display:flex;align-items:center;color:#cbd5e1;font-weight:700;padding:0 0.1rem;" aria-hidden="true">→</div>
            @endif
        @endforeach
    </div>
@endif
