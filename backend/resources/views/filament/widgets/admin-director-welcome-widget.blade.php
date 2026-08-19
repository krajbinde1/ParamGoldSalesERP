<x-filament-widgets::widget class="fi-admin-director-welcome-widget">
    <div class="pg-dash-welcome">
        <div>
            <p class="pg-dash-welcome__eyebrow">
                <span class="pg-dash-welcome__eyebrow-dot" aria-hidden="true"></span>
                Live Dashboard
            </p>
            <h2 class="pg-dash-welcome__title">Welcome back, {{ $userName }}</h2>
            <p class="pg-dash-welcome__product">ParamGold Sales ERP · {{ $roleLabel }}</p>
            <p class="pg-dash-welcome__date">{{ $currentDate }}</p>
        </div>
        <div class="pg-dash-welcome__avatar" aria-hidden="true">
            {{ strtoupper(substr($userName, 0, 1)) }}
        </div>
    </div>

    <div class="pg-dash-kpi-grid">
        @foreach ($kpis as $kpi)
            <div class="pg-dash-kpi">
                <div class="pg-dash-icon pg-dash-icon--{{ $kpi['tone'] }}" aria-hidden="true">
                    <span class="pg-dash-icon__glyph">◆</span>
                </div>
                <div class="pg-dash-kpi__body">
                    <p class="pg-dash-kpi__label">{{ $kpi['label'] }}</p>
                    <p class="pg-dash-kpi__value">{{ $kpi['value'] }}</p>
                    <p class="pg-dash-kpi__meta">{{ $kpi['meta'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="pg-dash-card pg-dash-section" style="margin-top: 0.75rem;">
        <div class="pg-dash-section__head" style="margin-bottom: 0.65rem;">
            <div>
                <h3 class="pg-dash-section__title">Team Today</h3>
                <p class="pg-dash-section__subtitle">Attendance and field activity snapshot</p>
            </div>
        </div>
        <div class="pg-dash-team-grid" style="margin-top: 0;">
            @foreach ($teamToday as $chip)
                <div class="pg-dash-team-chip">
                    <p class="pg-dash-team-chip__label">{{ $chip['label'] }}</p>
                    <p class="pg-dash-team-chip__value">{{ $chip['value'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
