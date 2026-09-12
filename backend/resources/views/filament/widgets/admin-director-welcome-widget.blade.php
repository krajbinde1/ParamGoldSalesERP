<x-filament-widgets::widget class="fi-admin-director-welcome-widget">
    <div class="pg-admin-dash" wire:poll.30s>
        <div class="pg-card pg-header">
            <div>
                <p class="pg-live">
                    <span class="pg-live__dot" aria-hidden="true"></span>
                    Director Dashboard
                </p>
                <h2 class="pg-header__title">Welcome back, {{ $userName }}</h2>
                <p class="pg-header__lead">Company status at a glance — money, approvals, delays, and field activity.</p>
                <p class="pg-header__date">{{ $currentDate }} · {{ $roleLabel }}</p>
            </div>
            <div class="pg-header__aside">
                <div
                    class="pg-tally-status"
                    x-data="{ open: false }"
                    @keydown.escape.window="open = false"
                    @click.outside="open = false"
                >
                    <button
                        type="button"
                        class="pg-tally-status__btn"
                        @click="open = !open"
                        :aria-expanded="open.toString()"
                        aria-haspopup="dialog"
                        aria-label="{{ $tallyStatus['label'] }}. Last Heartbeat: {{ $tallyStatus['last_sync_label'] }}"
                    >
                        <span class="pg-tally-status__row">
                            <span
                                class="pg-tally-status__dot {{ ($tallyStatus['connected'] ?? false) ? 'pg-tally-status__dot--on' : 'pg-tally-status__dot--off' }}"
                                aria-hidden="true"
                            ></span>
                            {{ $tallyStatus['label'] }}
                        </span>
                        <span class="pg-tally-status__sync">Last Heartbeat: {{ $tallyStatus['last_sync_label'] }}</span>
                    </button>
                    <div
                        class="pg-tally-status__pop"
                        x-show="open"
                        x-cloak
                        x-transition.opacity.duration.120ms
                        role="dialog"
                        aria-label="Tally Connector status"
                    >
                        @if ($tallyStatus['connected'] ?? false)
                            <p class="pg-tally-status__pop-row">
                                <span>Connector Name</span>
                                <strong>{{ $tallyStatus['connector_name'] }}</strong>
                            </p>
                            <p class="pg-tally-status__pop-row">
                                <span>Tally Company</span>
                                <strong>{{ $tallyStatus['tally_company'] }}</strong>
                            </p>
                            <p class="pg-tally-status__pop-row">
                                <span>Last Heartbeat</span>
                                <strong>{{ $tallyStatus['last_heartbeat_label'] }}</strong>
                            </p>
                            <p class="pg-tally-status__pop-row">
                                <span>Last Tally Sync</span>
                                <strong>{{ $tallyStatus['last_tally_sync_label'] }}</strong>
                            </p>
                        @else
                            <p class="pg-tally-status__pop-row">
                                <span>Last connected</span>
                                <strong>{{ $tallyStatus['last_connected_label'] }}</strong>
                            </p>
                            <p class="pg-tally-status__hint">{{ $tallyStatus['offline_hint'] }}</p>
                        @endif
                    </div>
                </div>
                <div class="pg-avatar" aria-hidden="true">{{ strtoupper(substr($userName, 0, 1)) }}</div>
            </div>
        </div>

        <div class="pg-kpi-grid pg-kpi-grid--6">
            @foreach ($kpis as $kpi)
                @php
                    $tag = filled($kpi['url'] ?? null) ? 'a' : 'div';
                @endphp
                <{{ $tag }}
                    @if (filled($kpi['url'] ?? null)) href="{{ $kpi['url'] }}" @endif
                    class="pg-card pg-kpi {{ ($kpi['alert'] ?? false) ? 'pg-kpi--alert' : '' }}"
                    aria-label="{{ $kpi['label'] }}: {{ $kpi['value'] }}"
                >
                    <div class="pg-icon pg-icon--{{ $kpi['tone'] }}" aria-hidden="true">
                        <x-filament::icon :icon="$kpi['icon']" />
                    </div>
                    <div class="pg-kpi__body">
                        <p class="pg-kpi__label">{{ $kpi['label'] }}</p>
                        <p class="pg-kpi__value">{{ $kpi['value'] }}</p>
                        @if (filled($kpi['hint'] ?? null))
                            <p class="pg-kpi__meta">{{ $kpi['hint'] }}</p>
                        @endif
                    </div>
                </{{ $tag }}>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
