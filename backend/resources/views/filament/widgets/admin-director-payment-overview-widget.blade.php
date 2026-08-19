<x-filament-widgets::widget class="fi-admin-director-payment-overview-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Payment Approval</h2>
                    <p class="pg-section-sub">Approval pipeline snapshot</p>
                </div>
            </div>

            <div class="pg-pipeline">
                @foreach ($stats as $stat)
                    @if ($stat['url'])
                        <a href="{{ $stat['url'] }}" class="pg-pipeline__step">
                            <div class="pg-icon pg-icon--{{ $stat['tone'] }}" style="margin: 0 auto;" aria-hidden="true">
                                <x-filament::icon :icon="$stat['icon']" />
                            </div>
                            <p class="pg-pipeline__label">{{ $stat['label'] }}</p>
                            <p class="pg-pipeline__value">{{ $stat['value'] }}</p>
                            @if ($stat['showArrow'])
                                <span class="pg-pipeline__arrow" aria-hidden="true">›</span>
                            @endif
                        </a>
                    @else
                        <div class="pg-pipeline__step">
                            <div class="pg-icon pg-icon--{{ $stat['tone'] }}" style="margin: 0 auto;" aria-hidden="true">
                                <x-filament::icon :icon="$stat['icon']" />
                            </div>
                            <p class="pg-pipeline__label">{{ $stat['label'] }}</p>
                            <p class="pg-pipeline__value">{{ $stat['value'] }}</p>
                            @if ($stat['showArrow'])
                                <span class="pg-pipeline__arrow" aria-hidden="true">›</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
