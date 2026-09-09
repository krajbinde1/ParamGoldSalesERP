<x-filament-panels::page>
    @php
        $timeline = $this->timelineDetail();
        $counts = $timeline ? [] : $this->dashboardCounts();
    @endphp

    @if ($timeline)
        <div class="mb-4">
            <x-filament::button color="gray" wire:click="closeTimeline" icon="heroicon-o-arrow-left">
                Back to list
            </x-filament::button>
        </div>

        @include('filament.pages.partials.payment-follow-up-timeline', ['detail' => $timeline])
    @else
        @include('filament.partials.paramgold-summary-cards', [
            'cards' => [
                [
                    'label' => 'Due Today',
                    'value' => $counts['due_today'] ?? 0,
                    'tone' => 'warning',
                    'active' => ($this->data['status'] ?? null) === \App\Services\PaymentFollowUps\PaymentFollowUpStatus::DUE_TODAY,
                    'wireClick' => "\$set('data.status', 'due_today')",
                ],
                [
                    'label' => 'Overdue',
                    'value' => $counts['overdue'] ?? 0,
                    'tone' => 'danger',
                    'active' => ($this->data['status'] ?? null) === \App\Services\PaymentFollowUps\PaymentFollowUpStatus::OVERDUE,
                    'wireClick' => "\$set('data.status', 'overdue')",
                ],
                [
                    'label' => 'Upcoming',
                    'value' => $counts['upcoming'] ?? 0,
                    'tone' => 'info',
                    'active' => ($this->data['status'] ?? null) === \App\Services\PaymentFollowUps\PaymentFollowUpStatus::UPCOMING,
                    'wireClick' => "\$set('data.status', 'upcoming')",
                ],
                [
                    'label' => 'No Follow-up Set',
                    'value' => $counts['no_follow_up'] ?? 0,
                    'tone' => 'primary',
                    'active' => ($this->data['status'] ?? null) === \App\Services\PaymentFollowUps\PaymentFollowUpStatus::NO_FOLLOW_UP,
                    'wireClick' => "\$set('data.status', 'no_follow_up')",
                ],
                [
                    'label' => 'Payment Received / Closed',
                    'value' => $counts['closed'] ?? 0,
                    'tone' => 'success',
                    'active' => ($this->data['status'] ?? null) === \App\Services\PaymentFollowUps\PaymentFollowUpStatus::CLOSED,
                    'wireClick' => "\$set('data.status', 'closed')",
                ],
            ],
        ])

        <div class="inventory-reports-filters mt-4 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-4">
            {{ $this->form }}
        </div>

        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            Monitoring only. This page does not change dealer ledger, outstanding, or collection entries.
        </p>

        <div class="inventory-reports-table-wrap mt-3">
            {{ $this->table }}
        </div>
    @endif
</x-filament-panels::page>
