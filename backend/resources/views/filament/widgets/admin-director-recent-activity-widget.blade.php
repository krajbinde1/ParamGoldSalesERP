<x-filament-widgets::widget class="fi-admin-director-recent-activity-widget">
    <x-filament::section>
        <div class="pg-dash-section__head">
            <div>
                <h2 class="pg-dash-section__title">Recent Activity</h2>
                <p class="pg-dash-section__subtitle">Latest operational records</p>
            </div>
            <div class="pg-dash-seg" role="tablist" aria-label="Recent activity tabs">
                @foreach ($tabs as $key => $tab)
                    <button
                        type="button"
                        wire:click="setActivityTab('{{ $key }}')"
                        @class(['pg-dash-seg__btn', 'pg-dash-seg__btn--active' => $activeTab === $key])
                    >
                        {{ $tab['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        <div>
            @forelse ($activity[$activeTab] ?? [] as $item)
                <div class="pg-dash-activity-item">
                    <div>
                        <p class="paramgold-activity-item__title">{{ $item['title'] }}</p>
                        <p class="paramgold-activity-item__meta">
                            {{ $item['employee'] ?? '—' }}
                            @if (filled($item['subtitle'])) · {{ $item['subtitle'] }} @endif
                            @if (filled($item['date'])) · {{ $item['date'] }} @endif
                        </p>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <span class="paramgold-status-pill paramgold-status-pill--{{ $item['status_color'] }}">{{ $item['status_label'] }}</span>
                        <a href="{{ $tabs[$activeTab]['viewUrl']($item['id']) }}" class="pg-dash-link">View</a>
                    </div>
                </div>
            @empty
                <p class="pg-dash-empty">No recent records found.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
