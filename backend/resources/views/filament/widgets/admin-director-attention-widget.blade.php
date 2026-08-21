<x-filament-widgets::widget class="fi-admin-director-attention-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Attention Required</h2>
                    <p class="pg-section-sub">Issues that need monitoring or action</p>
                </div>
            </div>

            <div class="pg-attention">
                @foreach ($items as $item)
                    @if (filled($item['url'] ?? null))
                        <a href="{{ $item['url'] }}" class="pg-attention__item pg-attention__item--{{ $item['tone'] }}">
                            <span class="pg-attention__dot" aria-hidden="true"></span>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @else
                        <div class="pg-attention__item pg-attention__item--{{ $item['tone'] }}">
                            <span class="pg-attention__dot" aria-hidden="true"></span>
                            <span>{{ $item['label'] }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
