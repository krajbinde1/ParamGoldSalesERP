@php
    /** @var \App\Filament\Resources\Dealers\Pages\ListDealers $page */
    $page = $schemaComponent->getLivewire();
    $overview = $page->networkOverview();
    $summary = $overview['summary'];
    $districts = $overview['districts'];
    $talukas = $overview['talukas'];
    $areas = $overview['areas'];
    $options = $overview['filter_options'];
    $selectedDistrict = $page->networkDistrict;
    $selectedTaluka = $page->networkTaluka;
    $districtMax = max(array_merge([1], array_column($districts, 'count')));
    $talukaMax = max(array_merge([1], array_column($talukas, 'count')));
    $showMapToggle = (bool) $overview['has_mappable_dealers'];
    $isMapView = $showMapToggle && $page->networkView === 'map';
    $markers = $overview['markers'];
@endphp

<style>
    .pg-dealer-network {
        --pg-navy: #0F172A;
        --pg-muted: #64748B;
        --pg-border: #E2E8F0;
        --pg-teal: #0F766E;
        margin: 0 0 1.25rem;
        font-family: inherit;
    }
    .pg-dealer-network h2 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--pg-navy);
    }
    .pg-dealer-network__lead {
        margin: 0.2rem 0 0;
        font-size: 0.8125rem;
        color: var(--pg-muted);
    }
    .pg-dealer-network__card {
        background: #fff;
        border: 1px solid var(--pg-border);
        border-radius: 0.75rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }
    .pg-dealer-network__head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        margin-bottom: 0.85rem;
    }
    .pg-dealer-network__filters {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
        padding: 0.9rem 1rem;
        margin-bottom: 0.85rem;
    }
    @media (min-width: 768px) {
        .pg-dealer-network__filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1280px) {
        .pg-dealer-network__filters { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }
    .pg-dealer-network__field label {
        display: block;
        margin-bottom: 0.25rem;
        font-size: 0.7rem;
        font-weight: 650;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }
    .pg-dealer-network__field select {
        width: 100%;
        border: 1px solid var(--pg-border);
        border-radius: 0.5rem;
        background: #fff;
        color: var(--pg-navy);
        font-size: 0.8125rem;
        padding: 0.45rem 0.55rem;
    }
    .pg-dealer-network__reset {
        align-self: end;
    }
    .pg-dealer-network__kpis {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }
    @media (min-width: 900px) {
        .pg-dealer-network__kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .pg-dealer-network__kpi {
        padding: 0.9rem 1rem;
    }
    .pg-dealer-network__kpi span {
        display: block;
        font-size: 0.7rem;
        font-weight: 650;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--pg-muted);
    }
    .pg-dealer-network__kpi strong {
        display: block;
        margin-top: 0.3rem;
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--pg-navy);
        letter-spacing: -0.03em;
    }
    .pg-dealer-network__charts {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }
    @media (min-width: 1024px) {
        .pg-dealer-network__charts { grid-template-columns: 1fr 1fr; }
    }
    .pg-dealer-network__panel { padding: 0.95rem 1rem 1rem; }
    .pg-dealer-network__panel-title {
        margin: 0 0 0.15rem;
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--pg-navy);
    }
    .pg-dealer-network__panel-sub {
        margin: 0 0 0.75rem;
        font-size: 0.75rem;
        color: var(--pg-muted);
    }
    .pg-dealer-network__bars {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        max-height: 22rem;
        overflow-y: auto;
    }
    .pg-dealer-network__bar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 2.75rem;
        gap: 0.5rem;
        align-items: center;
        width: 100%;
        padding: 0.15rem 0.2rem;
        border: 0;
        background: transparent;
        text-align: left;
        cursor: pointer;
        border-radius: 0.4rem;
    }
    .pg-dealer-network__bar:hover { background: #F8FAFC; }
    .pg-dealer-network__bar.is-active { background: rgba(15, 118, 110, 0.08); }
    .pg-dealer-network__bar-label {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.78rem;
        color: var(--pg-navy);
        margin-bottom: 0.15rem;
    }
    .pg-dealer-network__bar-label b { font-weight: 650; }
    .pg-dealer-network__bar-track {
        height: 0.45rem;
        border-radius: 999px;
        background: #F1F5F9;
        overflow: hidden;
    }
    .pg-dealer-network__bar-fill {
        height: 100%;
        border-radius: 999px;
        background: #94A3B8;
    }
    .pg-dealer-network__bar.is-active .pg-dealer-network__bar-fill { background: var(--pg-teal); }
    .pg-dealer-network__bar-count {
        font-size: 0.78rem;
        font-weight: 650;
        color: var(--pg-navy);
        text-align: right;
    }
    .pg-dealer-network__empty {
        margin: 0;
        padding: 1.25rem 0.25rem;
        font-size: 0.8125rem;
        color: var(--pg-muted);
        text-align: center;
    }
    .pg-dealer-network__areas {
        padding: 0.95rem 1rem 1rem;
        margin-bottom: 0.15rem;
    }
    .pg-dealer-network__area-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
    }
    @media (min-width: 768px) {
        .pg-dealer-network__area-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (min-width: 1280px) {
        .pg-dealer-network__area-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .pg-dealer-network__area {
        width: 100%;
        border: 1px solid var(--pg-border);
        border-radius: 0.65rem;
        background: #fff;
        padding: 0.7rem 0.75rem;
        text-align: left;
        cursor: pointer;
    }
    .pg-dealer-network__area:hover { border-color: #CBD5E1; background: #F8FAFC; }
    .pg-dealer-network__area.is-active {
        border-color: #99F6E4;
        background: #F0FDFA;
    }
    .pg-dealer-network__area strong {
        display: block;
        font-size: 0.82rem;
        color: var(--pg-navy);
        margin-bottom: 0.35rem;
    }
    .pg-dealer-network__area-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem 0.7rem;
        font-size: 0.72rem;
        color: var(--pg-muted);
    }
    .pg-dealer-network__toggle {
        display: inline-flex;
        border: 1px solid var(--pg-border);
        border-radius: 0.5rem;
        overflow: hidden;
        background: #fff;
    }
    .pg-dealer-network__toggle button {
        border: 0;
        background: transparent;
        padding: 0.4rem 0.7rem;
        font-size: 0.75rem;
        font-weight: 650;
        color: var(--pg-muted);
        cursor: pointer;
    }
    .pg-dealer-network__toggle button.is-active {
        background: #F1F5F9;
        color: var(--pg-navy);
    }
    .pg-dealer-network__map {
        height: 22rem;
        width: 100%;
        border-radius: 0.55rem;
        border: 1px solid var(--pg-border);
        overflow: hidden;
        background: #F8FAFC;
    }
    .pg-dealer-network .leaflet-popup-content {
        font-size: 0.8rem;
        color: var(--pg-navy);
        margin: 0.55rem 0.7rem;
        line-height: 1.45;
    }
    .pg-dealer-network .leaflet-popup-content strong { display: block; margin-bottom: 0.15rem; }
</style>

<section class="pg-dealer-network" wire:key="dealer-network-overview">
    <div class="pg-dealer-network__head">
        <div>
            <h2>Dealer Network Overview</h2>
            <p class="pg-dealer-network__lead">Area-wise coverage from existing dealer locations. Click a district or taluka to filter the table below.</p>
        </div>
        @if ($showMapToggle)
            <div class="pg-dealer-network__toggle" role="group" aria-label="Network view">
                <button type="button" class="{{ $isMapView ? '' : 'is-active' }}" wire:click="setNetworkView('chart')">Chart View</button>
                <button type="button" class="{{ $isMapView ? 'is-active' : '' }}" wire:click="setNetworkView('map')">Map View</button>
            </div>
        @endif
    </div>

    <div class="pg-dealer-network__card pg-dealer-network__filters">
        <div class="pg-dealer-network__field">
            <label for="pg-net-state">State</label>
            <select id="pg-net-state" wire:model.live="networkState">
                <option value="">All states</option>
                @foreach ($options['states'] as $state)
                    <option value="{{ $state }}">{{ $state }}</option>
                @endforeach
            </select>
        </div>
        <div class="pg-dealer-network__field">
            <label for="pg-net-district">District</label>
            <select id="pg-net-district" wire:model.live="networkDistrict">
                <option value="">All districts</option>
                @foreach ($options['districts'] as $district)
                    <option value="{{ $district }}">{{ $district }}</option>
                @endforeach
            </select>
        </div>
        <div class="pg-dealer-network__field">
            <label for="pg-net-taluka">Taluka</label>
            <select id="pg-net-taluka" wire:model.live="networkTaluka">
                <option value="">All talukas</option>
                @foreach ($options['talukas'] as $taluka)
                    <option value="{{ $taluka }}">{{ $taluka }}</option>
                @endforeach
            </select>
        </div>
        <div class="pg-dealer-network__field">
            <label for="pg-net-employee">Assigned Employee</label>
            <select id="pg-net-employee" wire:model.live="networkEmployeeId">
                <option value="">All employees</option>
                @foreach ($options['employees'] as $employeeId => $label)
                    <option value="{{ $employeeId }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="pg-dealer-network__field">
            <label for="pg-net-type">Dealer Type</label>
            <select id="pg-net-type" wire:model.live="networkDealerType">
                <option value="">All types</option>
                @foreach ($options['dealer_types'] as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div class="pg-dealer-network__field pg-dealer-network__reset">
            <label>&nbsp;</label>
            <x-filament::button color="gray" size="sm" wire:click="resetNetworkFilters">
                Reset Filters
            </x-filament::button>
        </div>
    </div>

    <div class="pg-dealer-network__kpis">
        <div class="pg-dealer-network__card pg-dealer-network__kpi">
            <span>Total Dealers</span>
            <strong>{{ number_format($summary['total_dealers']) }}</strong>
        </div>
        <div class="pg-dealer-network__card pg-dealer-network__kpi">
            <span>Total Districts Covered</span>
            <strong>{{ number_format($summary['total_districts']) }}</strong>
        </div>
        <div class="pg-dealer-network__card pg-dealer-network__kpi">
            <span>Total Talukas Covered</span>
            <strong>{{ number_format($summary['total_talukas']) }}</strong>
        </div>
        <div class="pg-dealer-network__card pg-dealer-network__kpi">
            <span>Total Villages Covered</span>
            <strong>{{ number_format($summary['total_villages']) }}</strong>
        </div>
    </div>

    @if ($isMapView)
        <div class="pg-dealer-network__card pg-dealer-network__panel" wire:key="dealer-network-map-{{ md5(json_encode($markers)) }}">
            <p class="pg-dealer-network__panel-title">Dealer Map</p>
            <p class="pg-dealer-network__panel-sub">Only dealers with saved latitude and longitude are shown. Locations are not estimated.</p>
            @if ($markers === [])
                <p class="pg-dealer-network__empty">No mapped dealer locations for the current filters.</p>
            @else
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
                <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
                <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
                <div id="pg-dealer-network-map" class="pg-dealer-network__map" wire:ignore></div>
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
                <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
                <script>
                    (function () {
                        const markers = @json($markers);
                        const el = document.getElementById('pg-dealer-network-map');
                        if (!el || typeof L === 'undefined' || !markers.length) {
                            return;
                        }
                        if (el._pgMap) {
                            el._pgMap.remove();
                        }
                        const map = L.map(el, { scrollWheelZoom: false });
                        el._pgMap = map;
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; OpenStreetMap',
                            maxZoom: 18,
                        }).addTo(map);
                        const cluster = (typeof L.markerClusterGroup === 'function')
                            ? L.markerClusterGroup()
                            : L.layerGroup();
                        const bounds = [];
                        const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
                            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                        }[char]));
                        markers.forEach((marker) => {
                            const latLng = [marker.latitude, marker.longitude];
                            bounds.push(latLng);
                            const popup = [
                                '<strong>' + (marker.dealer_code ? esc(marker.dealer_code) + ' · ' : '') + esc(marker.firm_name || 'Dealer') + '</strong>',
                                marker.district ? 'District: ' + esc(marker.district) : '',
                                marker.taluka ? 'Taluka: ' + esc(marker.taluka) : '',
                                marker.village ? 'Village: ' + esc(marker.village) : '',
                                marker.assigned_employee ? 'Assigned: ' + esc(marker.assigned_employee) : 'Assigned: —',
                            ].filter(Boolean).join('<br>');
                            L.marker(latLng).bindPopup(popup).addTo(cluster);
                        });
                        cluster.addTo(map);
                        if (bounds.length === 1) {
                            map.setView(bounds[0], 10);
                        } else {
                            map.fitBounds(bounds, { padding: [24, 24] });
                        }
                    })();
                </script>
            @endif
        </div>
    @else
        <div class="pg-dealer-network__charts">
            <div class="pg-dealer-network__card pg-dealer-network__panel">
                <p class="pg-dealer-network__panel-title">District-wise Dealer Network</p>
                <p class="pg-dealer-network__panel-sub">Click a district to filter the dealer table.</p>
                @if ($districts === [])
                    <p class="pg-dealer-network__empty">No district data for the current filters.</p>
                @else
                    <div class="pg-dealer-network__bars">
                        @foreach ($districts as $row)
                            <button
                                type="button"
                                class="pg-dealer-network__bar {{ $selectedDistrict === $row['name'] ? 'is-active' : '' }}"
                                title="{{ $row['name'] }} — {{ $row['count'] }} dealer{{ $row['count'] === 1 ? '' : 's' }}"
                                wire:click="selectNetworkDistrict({{ \Illuminate\Support\Js::from($row['name']) }})"
                            >
                                <div>
                                    <div class="pg-dealer-network__bar-label">
                                        <b>{{ $row['name'] }}</b>
                                    </div>
                                    <div class="pg-dealer-network__bar-track">
                                        <div class="pg-dealer-network__bar-fill" style="width: {{ max(6, round(($row['count'] / $districtMax) * 100)) }}%"></div>
                                    </div>
                                </div>
                                <span class="pg-dealer-network__bar-count">{{ $row['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="pg-dealer-network__card pg-dealer-network__panel">
                <p class="pg-dealer-network__panel-title">Taluka-wise Distribution</p>
                <p class="pg-dealer-network__panel-sub">
                    @if ($selectedDistrict)
                        Talukas in {{ $selectedDistrict }}. Click a taluka to filter further.
                    @elseif ($overview['talukas_are_top_overall'])
                        Top talukas overall. Click a taluka to filter the table.
                    @else
                        Click a taluka to filter the dealer table.
                    @endif
                </p>
                @if ($talukas === [])
                    <p class="pg-dealer-network__empty">No taluka data for the current filters.</p>
                @else
                    <div class="pg-dealer-network__bars">
                        @foreach ($talukas as $row)
                            <button
                                type="button"
                                class="pg-dealer-network__bar {{ $selectedTaluka === $row['name'] && ($selectedDistrict === null || $selectedDistrict === $row['district']) ? 'is-active' : '' }}"
                                title="{{ $row['name'] }}{{ $row['district'] ? ' (' . $row['district'] . ')' : '' }} — {{ $row['count'] }} dealer{{ $row['count'] === 1 ? '' : 's' }}"
                                wire:click="selectNetworkTaluka({{ \Illuminate\Support\Js::from($row['name']) }}, {{ \Illuminate\Support\Js::from($row['district']) }})"
                            >
                                <div>
                                    <div class="pg-dealer-network__bar-label">
                                        <b>{{ $row['name'] }}</b>
                                        @if (! $selectedDistrict && $row['district'])
                                            <span>{{ $row['district'] }}</span>
                                        @endif
                                    </div>
                                    <div class="pg-dealer-network__bar-track">
                                        <div class="pg-dealer-network__bar-fill" style="width: {{ max(6, round(($row['count'] / $talukaMax) * 100)) }}%"></div>
                                    </div>
                                </div>
                                <span class="pg-dealer-network__bar-count">{{ $row['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="pg-dealer-network__card pg-dealer-network__areas">
        <p class="pg-dealer-network__panel-title">Area Network</p>
        <p class="pg-dealer-network__panel-sub">Click a district card to filter the dealer table.</p>
        @if ($areas === [])
            <p class="pg-dealer-network__empty">No area summary for the current filters.</p>
        @else
            <div class="pg-dealer-network__area-grid">
                @foreach ($areas as $area)
                    <button
                        type="button"
                        class="pg-dealer-network__area {{ $selectedDistrict === $area['name'] ? 'is-active' : '' }}"
                        wire:click="selectNetworkDistrict({{ \Illuminate\Support\Js::from($area['name']) }})"
                    >
                        <strong>{{ $area['name'] }}</strong>
                        <div class="pg-dealer-network__area-meta">
                            <span>{{ $area['dealer_count'] }} dealers</span>
                            <span>{{ $area['taluka_count'] }} talukas</span>
                            <span>{{ $area['village_count'] }} villages</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</section>
