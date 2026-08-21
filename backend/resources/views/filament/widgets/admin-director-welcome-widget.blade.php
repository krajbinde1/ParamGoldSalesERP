<x-filament-widgets::widget class="fi-admin-director-welcome-widget">
    <div class="pg-admin-dash">
        <div class="pg-card pg-header">
            <div>
                <p class="pg-live">
                    <span class="pg-live__dot" aria-hidden="true"></span>
                    Director Dashboard
                </p>
                <h2 class="pg-header__title">Welcome back, {{ $userName }}</h2>
                <p class="pg-header__lead">Company status at a glance — money, approvals, delays, and field activity.</p>
                <p class="pg-header__date">{{ $currentDate }} · {{ $roleLabel }}</p>
            </div>
            <div class="pg-avatar" aria-hidden="true">{{ strtoupper(substr($userName, 0, 1)) }}</div>
        </div>

        <div class="pg-kpi-grid pg-kpi-grid--6">
            @foreach ($kpis as $kpi)
                @php
                    $tag = filled($kpi['url'] ?? null) ? 'a' : 'div';
                @endphp
                <{{ $tag }}
                    @if (filled($kpi['url'] ?? null)) href="{{ $kpi['url'] }}" @endif
                    class="pg-card pg-kpi {{ ($kpi['alert'] ?? false) ? 'pg-kpi--alert' : '' }}"
                    aria-label="{{ $kpi['label'] }}: {{ $kpi['value'] }}"
                >
                    <div class="pg-icon pg-icon--{{ $kpi['tone'] }}" aria-hidden="true">
                        <x-filament::icon :icon="$kpi['icon']" />
                    </div>
                    <div>
                        <p class="pg-kpi__label">{{ $kpi['label'] }}</p>
                        <p class="pg-kpi__value">{{ $kpi['value'] }}</p>
                        @if (filled($kpi['hint'] ?? null))
                            <p class="pg-kpi__meta">{{ $kpi['hint'] }}</p>
                        @endif
                    </div>
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
