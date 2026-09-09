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
        <div class="paramgold-summary-grid">
            <button type="button" class="paramgold-summary-card paramgold-summary-card--warning text-start" wire:click="$set('data.status', 'due_today')">
                <p class="paramgold-summary-card__label">Due Today</p>
                <p class="paramgold-summary-card__value">{{ $counts['due_today'] ?? 0 }}</p>
            </button>
            <button type="button" class="paramgold-summary-card paramgold-summary-card--danger text-start" wire:click="$set('data.status', 'overdue')">
                <p class="paramgold-summary-card__label">Overdue</p>
                <p class="paramgold-summary-card__value">{{ $counts['overdue'] ?? 0 }}</p>
            </button>
            <button type="button" class="paramgold-summary-card paramgold-summary-card--info text-start" wire:click="$set('data.status', 'upcoming')">
                <p class="paramgold-summary-card__label">Upcoming</p>
                <p class="paramgold-summary-card__value">{{ $counts['upcoming'] ?? 0 }}</p>
            </button>
            <button type="button" class="paramgold-summary-card paramgold-summary-card--primary text-start" wire:click="$set('data.status', 'no_follow_up')">
                <p class="paramgold-summary-card__label">No Follow-up Set</p>
                <p class="paramgold-summary-card__value">{{ $counts['no_follow_up'] ?? 0 }}</p>
            </button>
            <button type="button" class="paramgold-summary-card paramgold-summary-card--success text-start" wire:click="$set('data.status', 'closed')">
                <p class="paramgold-summary-card__label">Payment Received / Closed</p>
                <p class="paramgold-summary-card__value">{{ $counts['closed'] ?? 0 }}</p>
            </button>
        </div>

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
