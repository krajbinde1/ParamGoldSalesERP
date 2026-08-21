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

        <div class="pg-card pg-team">
            <div class="pg-section-head" style="margin-bottom: 0;">
                <div>
                    <h3 class="pg-section-title">Team Today</h3>
                    <p class="pg-section-sub">Attendance &amp; Field Activity</p>
                </div>
            </div>
            <div class="pg-team__strip">
                @foreach ($teamToday as $cell)
                    <a href="{{ $cell['url'] }}" class="pg-team__cell" aria-label="{{ $cell['label'] }}: {{ $cell['value'] }}">
                        <div class="pg-icon pg-icon--{{ $cell['tone'] }}" aria-hidden="true">
                            <x-filament::icon :icon="$cell['icon']" />
                        </div>
                        <div>
                            <p class="pg-team__label">{{ $cell['label'] }}</p>
                            <p class="pg-team__value">{{ $cell['value'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
