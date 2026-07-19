<x-filament-widgets::widget class="fi-admin-director-recent-activity-widget">
    <x-filament::section class="manager-dashboard-section">
        <x-slot name="heading">Recent Activity</x-slot>

        <div class="paramgold-activity-tabs">
            @foreach ($tabs as $key => $tab)
                <button
                    type="button"
                    wire:click="setActivityTab('{{ $key }}')"
                    @class(['manager-period-btn', 'manager-period-btn--active' => $activeTab === $key])
                >
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        <div class="paramgold-activity-list">
            @forelse ($activity[$activeTab] ?? [] as $item)
                <div class="paramgold-activity-item">
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
                        <a href="{{ $tabs[$activeTab]['viewUrl']($item['id']) }}" class="paramgold-view-link">View</a>
                    </div>
                </div>
            @empty
                <p class="manager-empty-state">No recent records found.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
