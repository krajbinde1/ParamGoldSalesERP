@php
    $status = strtolower((string) ($cycle['display_status'] ?? $cycle['status'] ?? 'open'));
    $expanded = (bool) $expanded;
@endphp

<section
    class="pg-pfu__card"
    x-data="{ open: {{ $expanded ? 'true' : 'false' }} }"
>
    @if ($expanded)
        <div class="pg-pfu__cycle-head">
            <h3 class="pg-pfu__cycle-title">Payment Cycle #{{ $cycle['cycle_number'] }}</h3>
            <div class="pg-pfu__badges">
                <span class="pg-pfu-badge pg-pfu-badge--{{ $status }}">{{ $cycle['status_label'] ?? strtoupper($status) }}</span>
            </div>
        </div>
        @include('filament.pages.partials.payment-follow-up-cycle-body', [
            'cycle' => $cycle,
            'fmtDate' => $fmtDate,
        ])
    @else
        <div class="pg-pfu__summary">
            <div class="pg-pfu__summary-copy">
                <div class="pg-pfu__badges" style="margin-bottom: 0.35rem;">
                    <h3 class="pg-pfu__cycle-title">Payment Cycle #{{ $cycle['cycle_number'] }}</h3>
                    <span class="pg-pfu-badge pg-pfu-badge--{{ $status }}">{{ $cycle['status_label'] ?? strtoupper($status) }}</span>
                </div>
                <p class="pg-pfu__summary-line">
                    Current Due {{ $cycle['current_due_label'] ?? '—' }}
                    · {{ $cycle['follow_up_count'] ?? 0 }} follow-ups
                    · {{ $cycle['payment_received_amount_label'] ?? '₹0' }} received
                    @if (! empty($cycle['closed_date']))
                        · Closed {{ $fmtDate($cycle['closed_date']) }}
                    @endif
                </p>
            </div>
            <button type="button" class="pg-pfu__toggle" x-on:click="open = !open" x-text="open ? 'Hide History' : 'View Full History'"></button>
        </div>
        <div x-show="open" x-cloak>
            @include('filament.pages.partials.payment-follow-up-cycle-body', [
                'cycle' => $cycle,
                'fmtDate' => $fmtDate,
            ])
        </div>
    @endif
</section>
