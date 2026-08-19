<x-filament-widgets::widget class="fi-admin-director-quick-actions-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Quick Actions</h2>
                <p class="pg-dash-section__subtitle">Common admin management shortcuts</p>
            </div>
        </div>

        <div class="pg-dash-actions">
            @foreach ($actions as $action)
                <a href="{{ $action['url'] }}" class="pg-dash-action">
                    <span class="pg-dash-icon pg-dash-icon--{{ $action['tone'] }}" aria-hidden="true">
                        <span class="pg-dash-icon__glyph">→</span>
                    </span>
                    <span>{{ $action['label'] }}</span>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
