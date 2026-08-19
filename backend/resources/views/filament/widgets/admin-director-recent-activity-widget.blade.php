<x-filament-widgets::widget class="fi-admin-director-recent-activity-widget">
    <div class="pg-admin-dash">
        <x-filament::section>
            <div class="pg-section-head">
                <div>
                    <h2 class="pg-section-title">Recent Activity</h2>
                    <p class="pg-section-sub">Latest operational records</p>
                </div>
                <div class="pg-seg" role="tablist" aria-label="Recent activity tabs">
                    @foreach ($tabs as $key => $tab)
                        <button
                            type="button"
                            wire:click="setActivityTab('{{ $key }}')"
                            @class(['pg-seg__btn', 'pg-seg__btn--active' => $activeTab === $key])
                        >
                            {{ $tab['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div>
                @forelse ($activity[$activeTab] ?? [] as $item)
                    <div class="pg-card" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:0.75rem;align-items:center;padding:0.8rem 1rem;margin-bottom:0.5rem;">
                        <div>
                            <p style="margin:0;font-size:0.875rem;font-weight:700;color:#0F172A;">{{ $item['title'] }}</p>
                            <p style="margin:0.15rem 0 0;font-size:0.75rem;color:#64748B;">
                                {{ $item['employee'] ?? '—' }}
                                @if (filled($item['subtitle'])) · {{ $item['subtitle'] }} @endif
                                @if (filled($item['date'])) · {{ $item['date'] }} @endif
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.5rem;">
                            <span class="paramgold-status-pill paramgold-status-pill--{{ $item['status_color'] }}">{{ $item['status_label'] }}</span>
                            <a href="{{ $tabs[$activeTab]['viewUrl']($item['id']) }}" class="pg-link">View</a>
                        </div>
                    </div>
                @empty
                    <p class="pg-empty">No recent records found.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
