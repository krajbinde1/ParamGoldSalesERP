@php
    $dealer = $detail['dealer'] ?? [];
    $cycles = collect($detail['cycles'] ?? []);
    $current = $cycles->firstWhere('is_current', true) ?? $cycles->last();
    $previous = $cycles
        ->when($current, fn ($rows) => $rows->where('id', '!=', $current['id'] ?? null))
        ->sortByDesc('cycle_number')
        ->values();

    $fmtDate = static function (?string $value): string {
        if (! filled($value)) {
            return '—';
        }

        return \Illuminate\Support\Carbon::parse($value)->timezone('Asia/Kolkata')->format('d M Y');
    };
@endphp

<style>
    .pg-pfu {
        --pg-navy: #0F172A;
        --pg-muted: #64748B;
        --pg-border: #E2E8F0;
        --pg-teal: #0F766E;
        display: grid;
        gap: 1rem;
        min-width: 0;
    }

    .pg-pfu [x-cloak] { display: none !important; }

    .pg-pfu__card {
        min-width: 0;
        overflow: hidden;
        background: #fff;
        border: 1px solid var(--pg-border);
        border-radius: 0.85rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .pg-pfu__header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem 1.25rem;
        padding: 1.15rem 1.25rem 1.2rem;
    }

    .pg-pfu__identity { min-width: 0; flex: 1 1 16rem; }

    .pg-pfu__kicker {
        margin: 0 0 0.35rem;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-pfu__name {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--pg-navy);
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .pg-pfu__assigned {
        margin: 0.4rem 0 0;
        font-size: 0.875rem;
        font-weight: 600;
        color: #334155;
        overflow-wrap: anywhere;
    }

    .pg-pfu__due {
        min-width: 0;
        flex: 0 1 16rem;
        padding: 0.85rem 1rem;
        border-radius: 0.75rem;
        background: #F0FDFA;
        border: 1px solid rgba(15, 118, 110, 0.18);
    }

    .pg-pfu__due-label {
        margin: 0;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--pg-teal);
    }

    .pg-pfu__due-value {
        margin: 0.35rem 0 0;
        font-size: 1.65rem;
        font-weight: 800;
        letter-spacing: -0.04em;
        color: var(--pg-navy);
        line-height: 1.15;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pg-pfu__readonly {
        margin: 0.45rem 0 0;
        font-size: 0.72rem;
        font-weight: 650;
        color: var(--pg-muted);
    }

    .pg-pfu__cycle-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        padding: 1rem 1.15rem 0.85rem;
        border-bottom: 1px solid var(--pg-border);
    }

    .pg-pfu__cycle-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--pg-navy);
        min-width: 0;
    }

    .pg-pfu__badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        min-width: 0;
    }

    .pg-pfu-badge {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: 0.2rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.6875rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pg-pfu-badge--open { background: #CCFBF1; color: #0F766E; }
    .pg-pfu-badge--overdue { background: #FEE2E2; color: #B91C1C; }
    .pg-pfu-badge--closed { background: #DCFCE7; color: #15803D; }
    .pg-pfu-badge--pending { background: #FEF3C7; color: #B45309; }
    .pg-pfu-badge--kept { background: #DCFCE7; color: #15803D; }
    .pg-pfu-badge--missed { background: #FEE2E2; color: #B91C1C; }
    .pg-pfu-badge--sent { background: #DCFCE7; color: #15803D; }
    .pg-pfu-badge--failed { background: #FEE2E2; color: #B91C1C; }
    .pg-pfu-badge--skipped,
    .pg-pfu-badge--gray { background: #F1F5F9; color: #475569; }

    .pg-pfu__metrics {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 9.75rem), 1fr));
        gap: 0.75rem;
        padding: 1rem 1.15rem;
        align-items: stretch;
    }

    .pg-pfu__metric {
        min-width: 0;
        overflow: hidden;
        padding: 0.75rem 0.8rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.7rem;
        background: #F8FAFC;
    }

    .pg-pfu__metric-label {
        margin: 0;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--pg-muted);
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .pg-pfu__metric-value {
        margin: 0.35rem 0 0;
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--pg-navy);
        line-height: 1.25;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pg-pfu__timeline {
        position: relative;
        padding: 0 1.15rem 1.15rem;
        display: grid;
        gap: 0.85rem;
    }

    .pg-pfu__event {
        position: relative;
        min-width: 0;
        padding: 0.9rem 1rem;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        background: #fff;
        overflow: hidden;
    }

    .pg-pfu__event--payment {
        border-color: rgba(22, 163, 74, 0.28);
        background: #F0FDF4;
    }

    .pg-pfu__event-top {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.5rem 0.85rem;
    }

    .pg-pfu__event-title {
        margin: 0;
        font-size: 0.9375rem;
        font-weight: 800;
        color: var(--pg-navy);
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .pg-pfu__event-meta {
        margin: 0.25rem 0 0;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--pg-muted);
        overflow-wrap: anywhere;
    }

    .pg-pfu__remark {
        margin: 0.65rem 0 0;
        font-size: 0.875rem;
        line-height: 1.45;
        color: #334155;
        overflow-wrap: anywhere;
    }

    .pg-pfu__facts {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 10.5rem), 1fr));
        gap: 0.65rem;
        margin-top: 0.75rem;
    }

    .pg-pfu__fact-label {
        margin: 0;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }

    .pg-pfu__fact-value {
        margin: 0.2rem 0 0;
        font-size: 0.84rem;
        font-weight: 700;
        color: var(--pg-navy);
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .pg-pfu__fact-value--amount {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pg-pfu__summary {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        padding: 0.95rem 1.15rem;
    }

    .pg-pfu__summary-copy {
        min-width: 0;
        flex: 1 1 14rem;
    }

    .pg-pfu__summary-line {
        margin: 0.2rem 0 0;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--pg-muted);
        overflow-wrap: anywhere;
    }

    .pg-pfu__toggle {
        border: 1px solid rgba(15, 118, 110, 0.28);
        background: #F0FDFA;
        color: var(--pg-teal);
        font-size: 0.78rem;
        font-weight: 750;
        border-radius: 0.55rem;
        padding: 0.4rem 0.7rem;
        cursor: pointer;
        white-space: nowrap;
    }

    .pg-pfu__toggle:hover { background: #CCFBF1; }

    .pg-pfu__empty {
        padding: 1.15rem;
        font-size: 0.875rem;
        color: var(--pg-muted);
    }

    @media (max-width: 639px) {
        .pg-pfu__header,
        .pg-pfu__cycle-head,
        .pg-pfu__metrics,
        .pg-pfu__timeline,
        .pg-pfu__summary { padding-left: 0.9rem; padding-right: 0.9rem; }

        .pg-pfu__due { flex: 1 1 100%; }
        .pg-pfu__name { font-size: 1.15rem; }
        .pg-pfu__due-value { font-size: 1.4rem; }
    }
</style>

<div class="pg-pfu">
    <section class="pg-pfu__card">
        <div class="pg-pfu__header">
            <div class="pg-pfu__identity">
                <p class="pg-pfu__kicker">Dealer</p>
                <h2 class="pg-pfu__name">{{ $dealer['firm_name'] ?? 'Dealer' }}</h2>
                <p class="pg-pfu__assigned">Assigned Employee: {{ $dealer['assigned_employee_name'] ?? '—' }}</p>
            </div>
            <div class="pg-pfu__due">
                <p class="pg-pfu__due-label">Current Due</p>
                <p class="pg-pfu__due-value">{{ $detail['current_due_label'] ?? $detail['current_outstanding_label'] ?? '—' }}</p>
                <p class="pg-pfu__readonly">Read-only monitoring</p>
            </div>
        </div>
    </section>

    @if ($current)
        @include('filament.pages.partials.payment-follow-up-cycle-card', [
            'cycle' => $current,
            'expanded' => true,
            'fmtDate' => $fmtDate,
        ])
    @endif

    @forelse ($previous as $cycle)
        @include('filament.pages.partials.payment-follow-up-cycle-card', [
            'cycle' => $cycle,
            'expanded' => false,
            'fmtDate' => $fmtDate,
        ])
    @empty
        @unless ($current)
            <section class="pg-pfu__card">
                <p class="pg-pfu__empty">No follow-up history yet for this assigned dealer.</p>
            </section>
        @endunless
    @endforelse
</div>
