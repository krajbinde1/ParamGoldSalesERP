@php
    /** @var list<array<string, mixed>> $steps */
    $steps = $steps ?? [];

    $displayLabels = [
        'created' => 'Order Placed',
        'approved' => 'Approved by Sales Manager',
        'pending_for_billing' => 'Sent for Bill by Production Supervisor',
        'billed' => 'Billed by Admin',
        'dispatched' => 'Dispatched by Production Supervisor',
    ];
@endphp

<div class="pg-order-workflow">
    <style>
        .pg-order-workflow { width: 100%; }
        .pg-order-workflow__list {
            margin: 0;
            padding: 4px 0 0;
            list-style: none;
        }
        .pg-order-workflow__item {
            position: relative;
            display: flex;
            gap: 14px;
            padding-bottom: 18px;
        }
        .pg-order-workflow__item:last-child { padding-bottom: 0; }
        .pg-order-workflow__rail {
            position: absolute;
            left: 11px;
            top: 26px;
            bottom: 0;
            width: 2px;
            background: #E2E8F0;
        }
        .pg-order-workflow__item:last-child .pg-order-workflow__rail { display: none; }
        .pg-order-workflow__rail--done { background: #86EFAC; }
        .pg-order-workflow__dot {
            position: relative;
            z-index: 1;
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            box-sizing: border-box;
        }
        .pg-order-workflow__dot--done {
            background: #16A34A;
            color: #fff;
            border: 2px solid #16A34A;
        }
        .pg-order-workflow__dot--current {
            background: #FFFBEB;
            color: #D97706;
            border: 2px solid #F59E0B;
        }
        .pg-order-workflow__dot--pending {
            background: #F8FAFC;
            color: #94A3B8;
            border: 2px solid #CBD5E1;
        }
        .pg-order-workflow__dot--rejected {
            background: #DC2626;
            color: #fff;
            border: 2px solid #DC2626;
        }
        .pg-order-workflow__body { min-width: 0; flex: 1; padding-top: 1px; }
        .pg-order-workflow__title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #0F172A;
            line-height: 1.35;
        }
        .pg-order-workflow__title--muted { color: #94A3B8; }
        .pg-order-workflow__title--current { color: #B45309; }
        .pg-order-workflow__title--rejected { color: #B91C1C; }
        .pg-order-workflow__status {
            margin: 3px 0 0;
            font-size: 12px;
            font-weight: 650;
            color: #64748B;
        }
        .pg-order-workflow__status--done { color: #15803D; }
        .pg-order-workflow__status--current { color: #D97706; }
        .pg-order-workflow__status--rejected { color: #DC2626; }
        .pg-order-workflow__meta {
            margin: 4px 0 0;
            font-size: 12px;
            color: #64748B;
            line-height: 1.4;
        }
        .pg-order-workflow__remark {
            margin: 8px 0 0;
            padding: 8px 10px;
            border-radius: 8px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            font-size: 12px;
            color: #475569;
            line-height: 1.4;
        }
        .pg-order-workflow__remark--rejected {
            background: #FEF2F2;
            border-color: #FECACA;
            color: #B91C1C;
        }
        .pg-order-workflow__empty {
            margin: 0;
            font-size: 13px;
            color: #94A3B8;
        }
    </style>

    @if (count($steps) === 0)
        <p class="pg-order-workflow__empty">No workflow steps available.</p>
    @else
        <ol class="pg-order-workflow__list">
            @foreach ($steps as $step)
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

                    $dotClass = $rejected
                        ? 'pg-order-workflow__dot--rejected'
                        : ($done
                            ? 'pg-order-workflow__dot--done'
                            : ($current
                                ? 'pg-order-workflow__dot--current'
                                : 'pg-order-workflow__dot--pending'));

                    $titleClass = $rejected
                        ? 'pg-order-workflow__title--rejected'
                        : ($done
                            ? ''
                            : ($current
                                ? 'pg-order-workflow__title--current'
                                : 'pg-order-workflow__title--muted'));

                    $statusLabel = $rejected
                        ? 'Rejected'
                        : ($done
                            ? 'Completed'
                            : ($statusText !== '' ? $statusText : 'Pending'));

                    $statusClass = $rejected
                        ? 'pg-order-workflow__status--rejected'
                        : ($done
                            ? 'pg-order-workflow__status--done'
                            : ($current ? 'pg-order-workflow__status--current' : ''));

                    // Never show raw step numbers — completed/rejected get icons; pending stays empty.
                    $dotMark = $rejected ? '!' : ($done ? '✓' : '');
                @endphp

                <li class="pg-order-workflow__item">
                    <span class="pg-order-workflow__rail {{ $done && ! $rejected ? 'pg-order-workflow__rail--done' : '' }}" aria-hidden="true"></span>
                    <span class="pg-order-workflow__dot {{ $dotClass }}" aria-hidden="true">{{ $dotMark }}</span>
                    <div class="pg-order-workflow__body">
                        <p class="pg-order-workflow__title {{ $titleClass }}">{{ $label }}</p>
                        <p class="pg-order-workflow__status {{ $statusClass }}">{{ $statusLabel }}</p>

                        @if ($actor !== '' || $role !== '')
                            <p class="pg-order-workflow__meta">
                                @if ($actor !== '')
                                    {{ $actor }}
                                @endif
                                @if ($actor !== '' && $role !== '')
                                    ·
                                @endif
                                @if ($role !== '')
                                    {{ $role }}
                                @endif
                            </p>
                        @endif

                        @if ($at !== '')
                            <p class="pg-order-workflow__meta">{{ str_replace('•', '·', $at) }}</p>
                        @endif

                        @if ($remark !== '')
                            <div class="pg-order-workflow__remark {{ $rejected ? 'pg-order-workflow__remark--rejected' : '' }}">
                                {!! nl2br(e($remark)) !!}
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
