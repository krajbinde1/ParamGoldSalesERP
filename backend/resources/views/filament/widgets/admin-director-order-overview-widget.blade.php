<x-filament-widgets::widget class="fi-admin-director-order-overview-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Order Pipeline</h2>
                    <p class="pg-section-sub">Where orders sit in the workflow</p>
                </div>
            </div>

            <div class="pg-flow">
                @foreach ($stages as $stage)
                    <a href="{{ $stage['url'] }}" class="pg-flow__stage {{ $stage['stuck'] ? 'pg-flow__stage--stuck' : '' }}">
                        <p class="pg-flow__label">{{ $stage['label'] }}</p>
                        <p class="pg-flow__value">{{ $stage['value'] }}</p>
                    </a>
                    @if (! $loop->last)
                        <span class="pg-flow__arrow" aria-hidden="true">→</span>
                    @endif
                @endforeach
                <a href="{{ $rejected['url'] }}" class="pg-flow__stage pg-flow__stage--rejected">
                    <p class="pg-flow__label">{{ $rejected['label'] }}</p>
                    <p class="pg-flow__value">{{ $rejected['value'] }}</p>
                </a>
            </div>

            @if (count($delays) > 0)
                <div class="pg-delay">
                    @foreach ($delays as $delay)
                        <a href="{{ $delay['url'] }}" class="pg-delay__item">{{ $delay['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
