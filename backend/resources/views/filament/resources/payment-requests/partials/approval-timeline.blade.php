@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];
@endphp

@if (count($steps) === 0)
    <p class="m-0 text-sm text-gray-500 dark:text-gray-400">No timeline available.</p>
@else
    <ol style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;">
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

                $dotStyle = match ($state) {
                    'rejected' => 'background:#ef4444;border-color:#ef4444;color:#fff;',
                    'done' => 'background:#14b8a6;border-color:#14b8a6;color:#fff;',
                    'pending' => 'background:#f59e0b;border-color:#f59e0b;color:#fff;',
                    default => 'background:#fff;border-color:#d1d5db;color:#9ca3af;',
                };

                $lineStyle = match ($state) {
                    'done' => 'background:#5eead4;',
                    'pending' => 'background:#fcd34d;',
                    'rejected' => 'background:#fca5a5;',
                    default => 'background:#e5e7eb;',
                };

                $badgeStyle = match ($state) {
                    'rejected' => 'background:#fef2f2;color:#b91c1c;',
                    'done' => 'background:#f0fdfa;color:#0f766e;',
                    'pending' => 'background:#fffbeb;color:#b45309;',
                    default => 'background:#f9fafb;color:#6b7280;',
                };

                $titleColor = match ($state) {
                    'rejected' => '#b91c1c',
                    'idle' => '#9ca3af',
                    default => 'inherit',
                };

                $mark = match ($state) {
                    'rejected' => '✕',
                    'done' => '✓',
                    'pending' => '●',
                    default => '○',
                };
            @endphp
            <li style="position:relative;display:flex;gap:12px;{{ $isLast ? '' : 'padding-bottom:18px;' }}">
                <div style="position:relative;display:flex;width:20px;flex-shrink:0;flex-direction:column;align-items:center;">
                    <span
                        aria-hidden="true"
                        style="position:relative;z-index:1;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:9999px;border:2px solid;font-size:11px;line-height:1;font-weight:700;{{ $dotStyle }}"
                    >{{ $mark }}</span>
                    @unless ($isLast)
                        <span aria-hidden="true" style="position:absolute;top:20px;bottom:0;width:2px;{{ $lineStyle }}"></span>
                    @endunless
                </div>

                <div style="min-width:0;flex:1;padding-top:1px;">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                        <div style="font-size:13px;font-weight:600;line-height:1.25;color:{{ $titleColor }};">{{ $label }}</div>
                        <span style="display:inline-flex;align-items:center;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:600;{{ $badgeStyle }}">
                            {{ $badge }}
                        </span>
                    </div>

                    @if ($actor !== '' || $role !== '')
                        <div style="margin-top:4px;font-size:13px;color:#374151;">
                            @if ($actor !== '' && $role !== '')
                                {{ $actor }} <span style="color:#9ca3af;">•</span> {{ $role }}
                            @elseif ($actor !== '')
                                {{ $actor }}
                            @else
                                {{ $role }}
                            @endif
                        </div>
                    @elseif ($pending)
                        <div style="margin-top:4px;font-size:13px;color:#b45309;">Awaiting action</div>
                    @elseif ($notStarted)
                        <div style="margin-top:4px;font-size:12px;color:#9ca3af;">Not started</div>
                    @endif

                    @if ($at !== '')
                        <div style="margin-top:2px;font-size:12px;color:#6b7280;">{{ $at }}</div>
                    @endif

                    @if ($remark !== '')
                        <div style="margin-top:6px;font-size:12px;color:#4b5563;">
                            <span style="font-weight:600;color:#6b7280;">Remark:</span>
                            {{ $remark }}
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
