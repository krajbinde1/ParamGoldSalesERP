<x-filament-widgets::widget class="fi-admin-director-order-overview-widget">
    <x-filament::section class="manager-dashboard-section">
        <x-slot name="heading">Order Overview</x-slot>
        <div class="manager-order-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));">
            @foreach ($stats as $stat)
                <a href="{{ $stat['url'] }}" class="manager-order-stat-card manager-order-stat-card--{{ $stat['color'] }}">
                    <p class="manager-order-stat-card__label">{{ $stat['label'] }}</p>
                    <p class="manager-order-stat-card__value">{{ $stat['value'] }}</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
