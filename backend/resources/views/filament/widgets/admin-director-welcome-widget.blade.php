<x-filament-widgets::widget class="fi-admin-director-welcome-widget">
    <div class="pg-admin-dash">
        <div class="pg-card pg-header">
            <div>
                <p class="pg-live">
                    <span class="pg-live__dot" aria-hidden="true"></span>
                    Live Dashboard
                </p>
                <h2 class="pg-header__title">Welcome back, {{ $userName }}</h2>
                <p class="pg-header__lead">Here’s what’s happening with ParamGold today.</p>
                <p class="pg-header__date">{{ $currentDate }} · {{ $roleLabel }}</p>
            </div>
            <div class="pg-avatar" aria-hidden="true">{{ strtoupper(substr($userName, 0, 1)) }}</div>
        </div>

        <div class="pg-kpi-grid">
            @foreach ($kpis as $kpi)
                <div class="pg-card pg-kpi">
                    <div class="pg-icon pg-icon--{{ $kpi['tone'] }}" aria-hidden="true">
                        <x-filament::icon :icon="$kpi['icon']" />
                    </div>
                    <div>
                        <p class="pg-kpi__label">{{ $kpi['label'] }}</p>
                        <p class="pg-kpi__value">{{ $kpi['value'] }}</p>
                        <p class="pg-kpi__meta">{{ $kpi['meta'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="pg-card pg-team">
            <div class="pg-section-head" style="margin-bottom: 0;">
                <div>
                    <h3 class="pg-section-title">Team Today</h3>
                    <p class="pg-section-sub">Attendance &amp; Field Activity</p>
                </div>
            </div>
            <div class="pg-team__strip">
                @foreach ($teamToday as $cell)
                    <div class="pg-team__cell">
                        <div class="pg-icon pg-icon--{{ $cell['tone'] }}" aria-hidden="true">
                            <x-filament::icon :icon="$cell['icon']" />
                        </div>
                        <div>
                            <p class="pg-team__label">{{ $cell['label'] }}</p>
                            <p class="pg-team__value">{{ $cell['value'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
