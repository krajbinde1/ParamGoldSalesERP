@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\DealerApplicationEvent> $events */
    $events = $events ?? collect();
    /** @var \App\Models\DealerApplication $application */
@endphp

@if ($events->isEmpty())
    <p class="text-sm text-gray-500">No timeline events yet.</p>
@else
    <ol style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:12px;">
        @foreach ($events as $event)
            @php
                $occurred = $event->created_at
                    ? $event->created_at->timezone('Asia/Kolkata')->format('d M Y • h:i A')
                    : '—';
                $code = data_get($event->payload, 'dealer_code');
            @endphp
            <li style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">
                <div style="font-size:13px;font-weight:700;">{{ $application->eventLabel($event) }}</div>
                <div style="margin-top:4px;font-size:12px;color:#6b7280;">
                    {{ $event->actor_name ?: 'System' }} • {{ $occurred }}
                </div>
                @if ($code)
                    <div style="margin-top:4px;font-size:12px;">Dealer Code: <strong>{{ $code }}</strong></div>
                @endif
                @if (filled($event->remark))
                    <div style="margin-top:4px;font-size:12px;">Remark: {{ $event->remark }}</div>
                @endif
            </li>
        @endforeach
    </ol>
@endif
