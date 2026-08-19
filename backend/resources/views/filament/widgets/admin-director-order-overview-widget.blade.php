<x-filament-widgets::widget class="fi-admin-director-order-overview-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Order Overview</h2>
                    <p class="pg-section-sub">Live order pipeline</p>
                </div>
            </div>

            <div class="pg-status-grid">
                @foreach ($stats as $stat)
                    <a href="{{ $stat['url'] }}" class="pg-status">
                        <div class="pg-icon pg-icon--{{ $stat['tone'] }}" aria-hidden="true">
                            <x-filament::icon :icon="$stat['icon']" />
                        </div>
                        <p class="pg-status__label">{{ $stat['label'] }}</p>
                        <p class="pg-status__value">{{ $stat['value'] }}</p>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
