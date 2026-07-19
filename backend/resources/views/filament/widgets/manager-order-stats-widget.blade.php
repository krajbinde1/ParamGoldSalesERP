<x-filament-widgets::widget class="fi-manager-order-stats-widget">
    @include('filament.widgets.partials.manager-dashboard-styles')

    <x-filament::section class="manager-dashboard-section">
        <x-slot name="heading">
            Order Summary
        </x-slot>

        <div class="manager-order-stats-grid">
            @foreach ($stats as $stat)
                <a
                    href="{{ $stat['url'] }}"
                    class="manager-order-stat-card manager-order-stat-card--{{ $stat['color'] }}"
                >
                    <p class="manager-order-stat-card__label">{{ $stat['label'] }}</p>
                    <p class="manager-order-stat-card__value">{{ $stat['value'] }}</p>
                    <p class="manager-order-stat-card__description">{{ $stat['description'] }}</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
