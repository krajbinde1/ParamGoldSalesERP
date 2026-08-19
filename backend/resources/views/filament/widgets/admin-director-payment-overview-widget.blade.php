<x-filament-widgets::widget class="fi-admin-director-payment-overview-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Payment Approval Overview</h2>
                <p class="pg-dash-section__subtitle">Snapshot of payment request stages</p>
            </div>
        </div>

        <div class="pg-dash-status-grid pg-dash-status-grid--4">
            @foreach ($stats as $stat)
                @if ($stat['url'])
                    <a href="{{ $stat['url'] }}" class="pg-dash-status pg-dash-status--{{ $stat['tone'] }}">
                        <div class="pg-dash-icon pg-dash-icon--{{ $stat['tone'] }}" aria-hidden="true">
                            <span class="pg-dash-icon__glyph">₹</span>
                        </div>
                        <p class="pg-dash-status__label">{{ $stat['label'] }}</p>
                        <p class="pg-dash-status__value">{{ $stat['value'] }}</p>
                    </a>
                @else
                    <div class="pg-dash-status pg-dash-status--{{ $stat['tone'] }}">
                        <div class="pg-dash-icon pg-dash-icon--{{ $stat['tone'] }}" aria-hidden="true">
                            <span class="pg-dash-icon__glyph">₹</span>
                        </div>
                        <p class="pg-dash-status__label">{{ $stat['label'] }}</p>
                        <p class="pg-dash-status__value">{{ $stat['value'] }}</p>
                    </div>
                @endif
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
