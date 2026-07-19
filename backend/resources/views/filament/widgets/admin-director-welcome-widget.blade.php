<x-filament-widgets::widget class="fi-admin-director-welcome-widget">
    @include('filament.partials.paramgold-admin-theme')

    <div class="paramgold-welcome-card">
        <div>
            <h2 class="paramgold-welcome-card__title">Welcome, {{ $userName }}</h2>
            <p class="paramgold-welcome-card__meta">Role: {{ $roleLabel }}</p>
            <p class="paramgold-welcome-card__meta">{{ $currentDate }}</p>
            <p class="paramgold-welcome-card__subtitle">ParamGold ERP Overview</p>
        </div>
        <div class="paramgold-welcome-card__avatar" aria-hidden="true">
            {{ strtoupper(substr($userName, 0, 1)) }}
        </div>
    </div>
</x-filament-widgets::widget>
