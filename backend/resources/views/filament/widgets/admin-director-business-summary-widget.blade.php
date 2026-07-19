<x-filament-widgets::widget class="fi-admin-director-business-summary-widget">
    <x-filament::section class="manager-dashboard-section">
        <x-slot name="heading">Main Business Summary</x-slot>
        <p class="manager-section-subtitle">Collection amount total: {{ $collectionAmount }}</p>

        <div class="paramgold-summary-grid">
            @foreach ($cards as $card)
                <a href="{{ $card['url'] }}" class="paramgold-summary-card paramgold-summary-card--{{ $card['color'] }}">
                    <p class="paramgold-summary-card__label">{{ $card['label'] }}</p>
                    <p class="paramgold-summary-card__value">{{ $card['value'] }}</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
