@php
    $dealer = $detail['dealer'] ?? [];
@endphp

<div class="space-y-4">
    <x-filament::section>
        <x-slot name="heading">{{ $dealer['firm_name'] ?? 'Dealer' }}</x-slot>
        <x-slot name="description">
            {{ $dealer['village'] ?? '-' }}
            · Assigned to {{ $dealer['assigned_employee_name'] ?? '-' }}
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-sm text-gray-500">Current Outstanding</p>
                <p class="text-lg font-semibold">{{ $detail['current_outstanding_label'] ?? '-' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Last Payment</p>
                <p class="text-lg font-semibold">
                    {{ $detail['last_payment_amount_label'] ?? '-' }}
                    @if (! empty($detail['last_payment_date']))
                        <span class="text-sm font-normal text-gray-500">on {{ \Illuminate\Support\Carbon::parse($detail['last_payment_date'])->format('d M Y') }}</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Status</p>
                <p class="text-lg font-semibold">{{ $detail['status_label'] ?? '-' }}</p>
            </div>
        </div>
    </x-filament::section>

    @forelse (($detail['cycles'] ?? []) as $cycle)
        <x-filament::section>
            <x-slot name="heading">Payment Follow-up Cycle #{{ $cycle['cycle_number'] }}</x-slot>
            <x-slot name="description">
                Opening Outstanding: {{ $cycle['opening_outstanding_label'] }}
                · {{ $cycle['status_label'] }}
                @if (! empty($cycle['closed_date']))
                    · Closed {{ \Illuminate\Support\Carbon::parse($cycle['closed_date'])->format('d M Y') }}
                @endif
            </x-slot>

            <div class="space-y-3">
                @foreach ($cycle['entries'] as $entry)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="font-semibold">
                                    {{ \Illuminate\Support\Carbon::parse($entry['follow_up_date'])->format('d M Y') }}
                                    · {{ $entry['entry_type'] === 'payment_received' ? 'Payment Received' : 'Follow-up' }}
                                </p>
                                <p class="text-sm text-gray-500">
                                    By {{ $entry['created_by_name'] ?? $entry['employee_name'] ?? '-' }}
                                </p>
                            </div>
                            <p class="text-sm font-medium">Outstanding: {{ $entry['outstanding_at_time_label'] }}</p>
                        </div>
                        <p class="mt-2 text-sm">{{ $entry['remark'] }}</p>
                        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt class="text-gray-500">Expected</dt>
                                <dd>{{ $entry['expected_amount_label'] ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Promised / Next Date</dt>
                                <dd>
                                    {{ ! empty($entry['next_follow_up_date']) ? \Illuminate\Support\Carbon::parse($entry['next_follow_up_date'])->format('d M Y') : '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Employee Reminder</dt>
                                <dd>
                                    {{ $entry['employee_notification_status'] }}
                                    @if (! empty($entry['employee_notification_sent_at']))
                                        · {{ \Illuminate\Support\Carbon::parse($entry['employee_notification_sent_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">WhatsApp Reminder</dt>
                                <dd>
                                    {{ $entry['whatsapp_status'] }}
                                    @if (! empty($entry['whatsapp_sent_at']))
                                        · {{ \Illuminate\Support\Carbon::parse($entry['whatsapp_sent_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>

            @if ($cycle['status'] === 'closed')
                <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm dark:bg-emerald-950/40">
                    Payment Received: {{ $cycle['payment_received_amount_label'] ?? '-' }}
                    · Closing Outstanding: {{ $cycle['closing_outstanding_label'] ?? '-' }}
                    · Cycle Status: CLOSED
                </div>
            @endif
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500">No follow-up history yet for this assigned dealer.</p>
        </x-filament::section>
    @endforelse
</div>
