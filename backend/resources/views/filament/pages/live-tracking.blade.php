<x-filament-panels::page>
    <div
        wire:ignore
        x-data="liveTracking(@js($this->pollSeconds()))"
        x-init="start()"
        x-on:destroy="stop()"
        class="live-tracking"
    >
        <div class="live-tracking-head">
            <div>
                <p class="live-tracking-kicker">Today only</p>
                <p class="live-tracking-subtitle">Real-time field employee locations. Completed days stay in Employee Routes.</p>
            </div>
            <div class="live-tracking-head-actions">
                <span class="live-tracking-banner" x-show="updateDelayed" x-cloak>Live update delayed</span>
                <button type="button" class="live-tracking-button" x-on:click="fitEmployees()">View all live</button>
            </div>
        </div>

        <div class="live-tracking-cards">
            <div class="live-card"><span>Active Employees</span><strong x-text="summary.active_employees ?? 0"></strong></div>
            <div class="live-card"><span>Punch In Today</span><strong x-text="summary.punch_in_today ?? 0"></strong></div>
            <div class="live-card"><span>Currently Moving</span><strong x-text="summary.moving ?? 0"></strong></div>
            <div class="live-card"><span>Stopped</span><strong x-text="summary.stopped ?? 0"></strong></div>
            <div class="live-card"><span>Last Updated</span><strong x-text="lastUpdatedLabel()"></strong></div>
        </div>

        <div class="live-tracking-layout">
            <div class="live-tracking-map-wrap">
                <div id="live-tracking-map"></div>
                <div class="live-route-panel" x-show="routeEmployee" x-cloak>
                    <div>
                        <strong x-text="routeEmployee?.employee_name"></strong>
                        <p>Today's live route</p>
                    </div>
                    <div class="live-route-stats">
                        <span>Punch in <b x-text="routeMeta.punch_in_label || '—'"></b></span>
                        <span>Duration <b x-text="durationLabel(routeMeta.working_minutes)"></b></span>
                        <span>Distance <b x-text="kmLabel(routeMeta.today_distance_km)"></b></span>
                        <span>Updated <b x-text="relative(routeMeta.recorded_at)"></b></span>
                    </div>
                    <button type="button" class="live-tracking-button" x-on:click="clearRoute()">Close route</button>
                </div>
            </div>

            <aside class="live-tracking-list">
                <input type="search" placeholder="Employee name or mobile" x-model="search">
                <div class="live-filters">
                    <template x-for="item in filters" :key="item.id">
                        <button type="button" :class="filter === item.id ? 'is-active' : ''" x-on:click="filter = item.id" x-text="item.label"></button>
                    </template>
                </div>
                <div class="live-rows">
                    <template x-for="employee in visibleEmployees()" :key="employee.employee_id">
                        <button type="button" class="live-row" :class="selectedId === employee.employee_id ? 'is-selected' : ''" x-on:click="focusEmployee(employee)">
                            <span class="live-row-name" x-text="employee.employee_name"></span>
                            <span class="live-badge" :data-status="employee.status" x-text="statusLabel(employee.status)"></span>
                            <span class="live-row-meta" x-text="'Last updated: ' + relative(employee.recorded_at)"></span>
                            <span class="live-row-meta" x-text="'Today: ' + kmLabel(employee.today_distance_km)"></span>
                        </button>
                    </template>
                    <p class="live-empty" x-show="visibleEmployees().length === 0">No punched-in employees match this filter.</p>
                </div>
            </aside>
        </div>
    </div>

    <style>
        .live-tracking { display: flex; flex-direction: column; gap: 12px; min-height: calc(100vh - 8rem); }
        .live-tracking-head, .live-tracking-head-actions, .live-route-panel, .live-filters { display: flex; align-items: center; gap: 8px; }
        .live-tracking-head { justify-content: space-between; }
        .live-tracking-kicker { font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #b45309; }
        .live-tracking-subtitle { color: #4b5563; font-size: 14px; }
        .live-tracking-banner { background: #fef3c7; color: #92400e; border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 700; }
        .live-tracking-button { border: 1px solid #d1d5db; background: white; border-radius: 8px; padding: 6px 10px; font-size: 13px; font-weight: 600; }
        .live-tracking-cards { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
        .live-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 12px; }
        .live-card span { display: block; color: #6b7280; font-size: 12px; }
        .live-card strong { font-size: 20px; }
        .live-tracking-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 12px; min-height: 640px; }
        .live-tracking-map-wrap { position: relative; min-height: 640px; border-radius: 16px; overflow: hidden; border: 1px solid #e5e7eb; }
        #live-tracking-map { height: 100%; min-height: 640px; }
        .live-route-panel { position: absolute; left: 12px; right: 12px; bottom: 12px; z-index: 500; background: white; border-radius: 12px; padding: 10px 12px; justify-content: space-between; box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .live-route-panel p { color: #6b7280; font-size: 12px; }
        .live-route-stats { display: flex; gap: 12px; color: #374151; font-size: 12px; }
        .live-tracking-list { background: white; border: 1px solid #e5e7eb; border-radius: 16px; padding: 10px; display: flex; flex-direction: column; gap: 8px; max-height: 640px; }
        .live-tracking-list input { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; }
        .live-filters { flex-wrap: wrap; }
        .live-filters button { border-radius: 999px; border: 1px solid #e5e7eb; padding: 4px 8px; font-size: 12px; }
        .live-filters button.is-active { background: #111827; color: white; }
        .live-rows { overflow: auto; display: flex; flex-direction: column; gap: 6px; }
        .live-row { text-align: left; border: 1px solid #e5e7eb; border-radius: 12px; padding: 8px 10px; }
        .live-row.is-selected { border-color: #d97706; }
        .live-row-name { display: block; font-weight: 700; }
        .live-row-meta { display: block; color: #6b7280; font-size: 12px; }
        .live-badge { display: inline-block; margin: 4px 0; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; }
        .live-badge[data-status="moving"] { background: #dcfce7; color: #166534; }
        .live-badge[data-status="stopped"] { background: #ffedd5; color: #9a3412; }
        .live-badge[data-status="delayed"] { background: #fef3c7; color: #92400e; }
        .live-badge[data-status="offline"] { background: #f3f4f6; color: #374151; }
        .live-empty { color: #6b7280; font-size: 13px; padding: 12px; }
        .live-marker { background: #111827; color: white; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; white-space: nowrap; border: 2px solid white; }
        .live-marker[data-status="moving"] { background: #15803d; }
        .live-marker[data-status="stopped"] { background: #c2410c; }
        .live-marker[data-status="delayed"] { background: #b45309; }
        .live-marker[data-status="offline"] { background: #6b7280; }
        .live-marker.is-current { box-shadow: 0 0 0 4px rgba(217, 119, 6, .35); }
        @media (max-width: 1024px) {
            .live-tracking-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .live-tracking-layout { grid-template-columns: 1fr; }
            .live-tracking-list, #live-tracking-map, .live-tracking-map-wrap { max-height: none; min-height: 420px; }
        }
    </style>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        function liveTracking(pollSeconds) {
            return {
                pollSeconds,
                summary: {},
                employees: [],
                search: '',
                filter: 'all',
                filters: [
                    { id: 'all', label: 'All' },
                    { id: 'moving', label: 'Moving' },
                    { id: 'stopped', label: 'Stopped' },
                    { id: 'offline', label: 'Offline' },
                ],
                selectedId: null,
                routeEmployee: null,
                routeMeta: {},
                latestPointId: null,
                updateDelayed: false,
                busy: false,
                timer: null,
                clock: null,
                now: Date.now(),
                map: null,
                cluster: null,
                markers: {},
                routeLine: null,
                punchMarker: null,
                stopMarkers: [],
                start() {
                    this.bootMap();
                    this.refresh();
                    this.timer = setInterval(() => this.refresh(), this.pollSeconds * 1000);
                    this.clock = setInterval(() => { this.now = Date.now(); }, 1000);
                },
                stop() {
                    clearInterval(this.timer);
                    clearInterval(this.clock);
                },
                bootMap() {
                    if (this.map || !window.L) return;
                    this.map = window.L.map('live-tracking-map', { zoomControl: true }).setView([20.5937, 78.9629], 5);
                    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap',
                    }).addTo(this.map);
                    this.cluster = window.L.layerGroup().addTo(this.map);
                },
                async refresh() {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        const data = await $wire.liveSnapshot();
                        this.summary = data.summary || {};
                        this.employees = data.employees || [];
                        this.drawMarkers();
                        this.updateDelayed = false;
                        if (this.selectedId) await this.refreshRoute();
                    } catch (error) {
                        this.updateDelayed = true;
                    } finally {
                        this.busy = false;
                    }
                },
                visibleEmployees() {
                    const query = this.search.trim().toLowerCase();
                    return this.employees.filter((employee) => {
                        if (this.filter === 'offline' && !['offline', 'delayed'].includes(employee.status)) return false;
                        if (this.filter !== 'all' && this.filter !== 'offline' && employee.status !== this.filter) return false;
                        if (!query) return true;
                        return `${employee.employee_name} ${employee.mobile || ''}`.toLowerCase().includes(query);
                    });
                },
                drawMarkers() {
                    if (!this.map) return;
                    const seen = new Set();
                    this.employees.forEach((employee) => {
                        if (employee.latitude == null || employee.longitude == null) return;
                        seen.add(employee.employee_id);
                        const position = [employee.latitude, employee.longitude];
                        let marker = this.markers[employee.employee_id];
                        if (!marker) {
                            marker = window.L.marker(position, { icon: this.icon(employee, false) });
                            marker.addTo(this.cluster);
                            marker.on('click', () => this.openPopup(employee, marker));
                            this.markers[employee.employee_id] = marker;
                        } else {
                            marker.setLatLng(position);
                            marker.setIcon(this.icon(employee, this.selectedId === employee.employee_id));
                        }
                        marker.employee = employee;
                    });
                    Object.keys(this.markers).forEach((id) => {
                        if (!seen.has(Number(id))) {
                            this.cluster.removeLayer(this.markers[id]);
                            delete this.markers[id];
                        }
                    });
                    if (!this.routeEmployee) this.fitEmployees();
                },
                icon(employee, current) {
                    return window.L.divIcon({
                        className: '',
                        html: `<span class="live-marker ${current ? 'is-current' : ''}" data-status="${employee.status}">${this.escape(employee.employee_name)}</span>`,
                        iconSize: [120, 28],
                        iconAnchor: [60, 14],
                    });
                },
                openPopup(employee, marker) {
                    const battery = employee.battery_percent == null ? 'Not sent by the phone' : `${employee.battery_percent}%`;
                    const location = employee.latitude == null
                        ? 'Waiting for GPS'
                        : `${Number(employee.latitude).toFixed(5)}, ${Number(employee.longitude).toFixed(5)}`;
                    marker.bindPopup(`
                        <strong>${this.escape(employee.employee_name)}</strong><br>
                        ${this.escape(employee.designation || '—')}<br>
                        ${this.escape(employee.mobile || '—')}<br>
                        Punch in: ${this.escape(employee.punch_in_label || '—')}<br>
                        Location: ${location}<br>
                        Last location: ${this.relative(employee.recorded_at)}<br>
                        Working: ${this.durationLabel(employee.working_minutes)}<br>
                        Today: ${this.kmLabel(employee.today_distance_km)}<br>
                        GPS: ${employee.accuracy == null ? '—' : `${Math.round(employee.accuracy)} m`}<br>
                        Battery: ${battery}<br>
                        ${this.statusLabel(employee.status)}<br>
                        <a href="${employee.employee_url}">Employee details</a>
                    `).openPopup();
                    marker.getPopup()?.on('add', () => {
                        const link = document.createElement('button');
                        link.type = 'button';
                        link.textContent = 'View live route';
                        link.style.marginTop = '6px';
                        link.onclick = () => this.showRoute(employee);
                        marker.getPopup().getElement()?.querySelector('.leaflet-popup-content')?.appendChild(link);
                    });
                },
                focusEmployee(employee) {
                    this.selectedId = employee.employee_id;
                    const marker = this.markers[employee.employee_id];
                    if (marker) {
                        this.map.setView(marker.getLatLng(), 16);
                        this.openPopup(employee, marker);
                    }
                },
                async showRoute(employee) {
                    this.selectedId = employee.employee_id;
                    this.routeEmployee = employee;
                    this.latestPointId = null;
                    this.clearRouteLayers();
                    await this.refreshRoute();
                },
                async refreshRoute() {
                    if (!this.selectedId) return;
                    const data = await $wire.liveRoute(this.selectedId, this.latestPointId);
                    const employee = this.employees.find((item) => item.employee_id === this.selectedId) || this.routeEmployee;
                    this.routeMeta = {
                        punch_in_label: data.punch_in?.label,
                        working_minutes: data.working_minutes,
                        today_distance_km: data.today_distance_km,
                        recorded_at: employee?.recorded_at,
                    };
                    this.appendRoute(data);
                },
                appendRoute(data) {
                    if (!this.map) return;
                    const points = data.points || [];
                    if (this.latestPointId == null) {
                        this.clearRouteLayers();
                        if (data.punch_in?.latitude != null) {
                            this.punchMarker = window.L.circleMarker([data.punch_in.latitude, data.punch_in.longitude], {
                                radius: 7, color: '#111827', fillColor: '#fbbf24', fillOpacity: 1,
                            }).addTo(this.map).bindTooltip('Punch in');
                        }
                        (data.stops || []).forEach((stop) => {
                            if (stop.latitude == null) return;
                            this.stopMarkers.push(window.L.circleMarker([stop.latitude, stop.longitude], {
                                radius: 5, color: '#9a3412', fillColor: '#fdba74', fillOpacity: .9,
                            }).addTo(this.map).bindTooltip('Stop'));
                        });
                    }
                    const existing = this.routeLine ? this.routeLine.getLatLngs() : [];
                    const next = existing.concat(points.map((point) => [point.latitude, point.longitude]));
                    if (next.length) {
                        if (!this.routeLine) {
                            this.routeLine = window.L.polyline(next, { color: '#d97706', weight: 4 }).addTo(this.map);
                        } else {
                            this.routeLine.setLatLngs(next);
                        }
                        this.map.fitBounds(this.routeLine.getBounds(), { padding: [24, 24] });
                    }
                    if (data.latest_point_id) this.latestPointId = data.latest_point_id;
                },
                clearRoute() {
                    this.routeEmployee = null;
                    this.selectedId = null;
                    this.latestPointId = null;
                    this.clearRouteLayers();
                    this.fitEmployees();
                },
                clearRouteLayers() {
                    if (this.routeLine) this.map.removeLayer(this.routeLine);
                    if (this.punchMarker) this.map.removeLayer(this.punchMarker);
                    this.stopMarkers.forEach((marker) => this.map.removeLayer(marker));
                    this.routeLine = null;
                    this.punchMarker = null;
                    this.stopMarkers = [];
                },
                fitEmployees() {
                    const positions = Object.values(this.markers).map((marker) => marker.getLatLng());
                    if (positions.length === 0 || !this.map) return;
                    this.map.fitBounds(window.L.latLngBounds(positions), { padding: [32, 32], maxZoom: 15 });
                },
                statusLabel(status) {
                    return { moving: 'Moving', stopped: 'Stopped', delayed: 'Location delayed', offline: 'Offline / No recent GPS' }[status] || status;
                },
                durationLabel(minutes) {
                    const value = Number(minutes || 0);
                    const hours = Math.floor(value / 60);
                    const rest = value % 60;
                    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
                },
                kmLabel(km) {
                    return `${Number(km || 0).toFixed(1)} km`;
                },
                relative(value) {
                    if (!value) return 'No GPS yet';
                    const seconds = Math.max(0, Math.round((this.now - new Date(value).getTime()) / 1000));
                    if (seconds < 60) return `${seconds} sec ago`;
                    const minutes = Math.round(seconds / 60);
                    return `${minutes} min ago`;
                },
                lastUpdatedLabel() {
                    return this.relative(this.summary.last_updated_at);
                },
                escape(value) {
                    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
                },
            };
        }
    </script>
</x-filament-panels::page>
