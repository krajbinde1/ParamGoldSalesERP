<x-filament-widgets::widget class="fi-admin-director-payment-overview-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Payment Approval</h2>
                    <p class="pg-section-sub">Your queue, next stage, and payments completed today</p>
                </div>
            </div>

            <div class="pg-status-grid pg-status-grid--3">
                @foreach ($stats as $stat)
                    @php
                        $tag = filled($stat['url'] ?? null) ? 'a' : 'div';
                    @endphp
                    <{{ $tag }}
                        @if (filled($stat['url'] ?? null)) href="{{ $stat['url'] }}" @endif
                        class="pg-status {{ ($stat['alert'] ?? false) ? 'pg-status--alert' : '' }}"
                    >
                        <div class="pg-icon pg-icon--{{ $stat['tone'] }}" aria-hidden="true">
                            <x-filament::icon :icon="$stat['icon']" />
                        </div>
                        <p class="pg-status__label">{{ $stat['label'] }}</p>
                        <p class="pg-status__value">{{ $stat['value'] }}</p>
                        <p class="pg-kpi__meta">{{ $stat['hint'] }}</p>
                    </{{ $tag }}>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
