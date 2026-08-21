<x-filament-widgets::widget class="fi-admin-director-collection-outstanding-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Collection &amp; Outstanding</h2>
                    <p class="pg-section-sub">Cash in and dealers near or over credit limit</p>
                </div>
            </div>

            <div class="pg-status-grid pg-status-grid--4">
                @foreach ($cards as $card)
                    <a href="{{ $card['url'] }}" class="pg-status">
                        <p class="pg-status__label">{{ $card['label'] }}</p>
                        <p class="pg-status__value">{{ $card['value'] }}</p>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
