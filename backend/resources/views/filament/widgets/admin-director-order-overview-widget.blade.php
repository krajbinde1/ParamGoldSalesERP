<x-filament-widgets::widget class="fi-admin-director-order-overview-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Order Overview</h2>
                <p class="pg-dash-section__subtitle">Live order status across the company</p>
            </div>
        </div>

        <div class="pg-dash-status-grid">
            @foreach ($stats as $stat)
                <a href="{{ $stat['url'] }}" class="pg-dash-status pg-dash-status--{{ $stat['tone'] }}">
                    <div class="pg-dash-icon pg-dash-icon--{{ $stat['tone'] === 'teal' ? 'teal' : ($stat['tone'] === 'amber' ? 'amber' : ($stat['tone'] === 'green' ? 'green' : ($stat['tone'] === 'blue' ? 'blue' : 'red'))) }}" aria-hidden="true">
                        <span class="pg-dash-icon__glyph">●</span>
                    </div>
                    <p class="pg-dash-status__label">{{ $stat['label'] }}</p>
                    <p class="pg-dash-status__value">{{ $stat['value'] }}</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
