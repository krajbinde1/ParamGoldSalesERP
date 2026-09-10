@php
    $metrics = [
        ['label' => 'Current Due', 'value' => $cycle['current_due_label'] ?? '—'],
        ['label' => 'Total Follow-ups', 'value' => (string) ($cycle['follow_up_count'] ?? 0)],
        ['label' => 'Total Commitments', 'value' => (string) ($cycle['commitment_count'] ?? 0)],
        ['label' => 'Missed Commitments', 'value' => (string) ($cycle['missed_commitment_count'] ?? 0)],
        ['label' => 'Total Payment Received', 'value' => $cycle['payment_received_amount_label'] ?? '₹0'],
        ['label' => 'Cycle Start Date', 'value' => $fmtDate($cycle['started_date'] ?? null)],
    ];

    if (! empty($cycle['closed_date'])) {
        $metrics[] = ['label' => 'Cycle Closed Date', 'value' => $fmtDate($cycle['closed_date'])];
    }
@endphp

<div class="pg-pfu__metrics">
    @foreach ($metrics as $metric)
        <div class="pg-pfu__metric">
            <p class="pg-pfu__metric-label">{{ $metric['label'] }}</p>
            <p class="pg-pfu__metric-value">{{ $metric['value'] }}</p>
        </div>
    @endforeach
</div>

<div class="pg-pfu__timeline">
    @forelse (($cycle['entries'] ?? []) as $entry)
        @php
            $isPayment = ($entry['timeline_kind'] ?? $entry['entry_type'] ?? '') === 'payment_received';
            $commitmentStatus = strtolower((string) ($entry['commitment_status'] ?? ''));
            $whatsappReminder = strtolower((string) ($entry['whatsapp_status'] ?? 'pending'));
            $whatsappCommitment = strtolower((string) ($entry['commitment_whatsapp_status'] ?? 'pending'));
        @endphp
        <article class="pg-pfu__event {{ $isPayment ? 'pg-pfu__event--payment' : '' }}">
            <div class="pg-pfu__event-top">
                <div>
                    <p class="pg-pfu__event-title">
                        @if ($isPayment)
                            Payment Received
                        @else
                            Follow-up #{{ $entry['follow_up_number'] ?? '—' }}
                        @endif
                    </p>
                    <p class="pg-pfu__event-meta">
                        {{ $entry['follow_up_at_label'] ?? $fmtDate($entry['follow_up_date'] ?? null) }}
                        @unless ($isPayment)
                            · {{ $entry['employee_name'] ?? $entry['created_by_name'] ?? '—' }}
                        @endunless
                    </p>
                </div>
                @if (! $isPayment && filled($entry['commitment_status_label'] ?? null))
                    <span class="pg-pfu-badge pg-pfu-badge--{{ $commitmentStatus }}">{{ $entry['commitment_status_label'] }}</span>
                @endif
            </div>

            @if ($isPayment)
                <div class="pg-pfu__facts">
                    <div>
                        <p class="pg-pfu__fact-label">Amount</p>
                        <p class="pg-pfu__fact-value pg-pfu__fact-value--amount">{{ $entry['payment_amount_label'] ?? $entry['expected_amount_label'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="pg-pfu__fact-label">Date</p>
                        <p class="pg-pfu__fact-value">{{ $fmtDate($entry['payment_date'] ?? $entry['follow_up_date'] ?? null) }}</p>
                    </div>
                    <div>
                        <p class="pg-pfu__fact-label">Updated Current Due</p>
                        <p class="pg-pfu__fact-value pg-pfu__fact-value--amount">{{ $entry['updated_current_due_label'] ?? $entry['outstanding_at_time_label'] ?? '—' }}</p>
                    </div>
                </div>
            @else
                @if (filled($entry['remark'] ?? null))
                    <p class="pg-pfu__remark">{{ $entry['remark'] }}</p>
                @endif
                <div class="pg-pfu__facts">
                    <div>
                        <p class="pg-pfu__fact-label">Commitment Amount</p>
                        <p class="pg-pfu__fact-value pg-pfu__fact-value--amount">{{ $entry['expected_amount_label'] ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="pg-pfu__fact-label">Commitment Date</p>
                        <p class="pg-pfu__fact-value">{{ $fmtDate($entry['next_follow_up_date'] ?? null) }}</p>
                    </div>
                    <div>
                        <p class="pg-pfu__fact-label">WhatsApp Reminder</p>
                        <p class="pg-pfu__fact-value">
                            <span class="pg-pfu-badge pg-pfu-badge--{{ in_array($whatsappReminder, ['sent', 'failed', 'skipped'], true) ? $whatsappReminder : 'gray' }}">
                                {{ $entry['whatsapp_status_label'] ?? '—' }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p class="pg-pfu__fact-label">WhatsApp Commitment</p>
                        <p class="pg-pfu__fact-value">
                            <span class="pg-pfu-badge pg-pfu-badge--{{ in_array($whatsappCommitment, ['sent', 'failed', 'skipped'], true) ? $whatsappCommitment : 'gray' }}">
                                {{ $entry['commitment_whatsapp_status_label'] ?? '—' }}
                            </span>
                        </p>
                    </div>
                </div>
            @endif
        </article>
    @empty
        <p class="pg-pfu__empty">No timeline entries in this cycle yet.</p>
    @endforelse
</div>
