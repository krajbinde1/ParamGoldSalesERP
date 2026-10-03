<x-filament-panels::page>
    <div
        wire:ignore
        x-data="liveTracking(@js([
            'pollSeconds' => $this->pollSeconds(),
            'snapshotUrl' => route('filament.admin.live-tracking.snapshot'),
            'routeBase' => url('/admin/live-tracking'),
            'employeeUrl' => \App\Filament\Resources\Employees\EmployeeResource::getUrl('index'),
        ]))"
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
                <button type="button" class="live-tracking-button" x-on:click="viewAll()">View all live</button>
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
                            <span class="live-row-meta" x-text="employee.status === 'no_gps' ? 'Punch in: ' + (employee.punch_in_label || '—') : 'Last location: ' + (employee.punch_in_location || 'Waiting for GPS')"></span>
                            <span class="live-row-meta" x-text="employee.status === 'no_gps' ? 'Last location: Waiting for GPS' : ('Last updated: ' + relative(employee.recorded_at))"></span>
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
        .live-badge[data-status="no_gps"] { background: #e0e7ff; color: #3730a3; }
        .live-empty { color: #6b7280; font-size: 13px; padding: 12px; }
        .emp-pin-icon, .leaflet-div-icon.emp-pin-icon { background: transparent; border: none; overflow: visible; }
        .emp-pin { width: 156px; display: flex; flex-direction: column; align-items: center; pointer-events: auto; }
        .emp-pin-head { position: relative; width: 52px; height: 52px; }
        .emp-pin-avatar { width: 46px; height: 46px; margin: 3px; border-radius: 50%; overflow: hidden; border: 3px solid #fff; box-shadow: 0 4px 14px rgba(0,0,0,.28); background: #1f2937; display: flex; align-items: center; justify-content: center; }
        .emp-pin.is-current .emp-pin-avatar { box-shadow: 0 0 0 3px #d97706, 0 4px 14px rgba(0,0,0,.28); }
        .emp-pin-photo { width: 100%; height: 100%; object-fit: cover; display: block; }
        .emp-pin-initials { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; font-weight: 800; letter-spacing: .02em; }
        .emp-pin-dot { position: absolute; right: 0; bottom: 0; width: 13px; height: 13px; border-radius: 50%; border: 2px solid #fff; background: #6b7280; z-index: 2; }
        .emp-pin[data-status="moving"] .emp-pin-dot { background: #16a34a; }
        .emp-pin[data-status="stopped"] .emp-pin-dot { background: #f59e0b; }
        .emp-pin[data-status="offline"] .emp-pin-dot { background: #6b7280; }
        .emp-pin[data-status="moving"] .emp-pin-avatar { box-shadow: 0 0 0 4px rgba(22, 163, 74, .25), 0 4px 14px rgba(0,0,0,.28); }
        .emp-pin.is-current[data-status="moving"] .emp-pin-avatar { box-shadow: 0 0 0 3px #d97706, 0 4px 14px rgba(0,0,0,.28); }
        .emp-pin-name { margin-top: 2px; max-width: 190px; background: rgba(255,255,255,.96); color: #111827; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 800; line-height: 1.3; text-align: center; white-space: nowrap; box-shadow: 0 2px 8px rgba(0,0,0,.18); }
        .emp-pin-heading { position: absolute; left: 50%; top: 50%; width: 14px; height: 14px; margin-left: -7px; margin-top: -7px; z-index: 3; pointer-events: none; }
        .emp-pin-heading::before { content: ""; position: absolute; left: 1px; top: -30px; border-left: 6px solid transparent; border-right: 6px solid transparent; border-bottom: 11px solid #15803d; filter: drop-shadow(0 0 1px #fff) drop-shadow(0 1px 1px rgba(0,0,0,.4)); }
        .emp-popup { display: flex; flex-direction: column; gap: 3px; min-width: 180px; color: #111827; }
        .emp-popup-photo, .emp-popup-initials { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
        .emp-popup-initials { display: flex; align-items: center; justify-content: center; background: #1f2937; color: white; font-weight: 800; }
        .emp-popup-name { font-size: 14px; }
        .emp-popup-status { font-weight: 700; }
        @media (max-width: 1024px) {
            .live-tracking-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .live-tracking-layout { grid-template-columns: 1fr; }
            .live-tracking-list, #live-tracking-map, .live-tracking-map-wrap { max-height: none; min-height: 420px; }
        }
    </style>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{ asset('js/live-tracking-motion.js') }}?v=1"></script>
    <script>
        function liveTracking(config) {
            const Motion = window.LiveTrackingMotion;
            return {
                pollSeconds: config.pollSeconds,
                snapshotUrl: config.snapshotUrl,
                routeBase: config.routeBase,
                employeeUrl: config.employeeUrl,
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
                booted: false,
                motions: {},
                routeTrack: null,
                routeBootstrapped: false,
                routeRequest: 0,
                routeHandledMotion: false,
                followId: null,
                followPaused: false,
                initialFitDone: false,
                raf: null,
                start() {
                    if (this.booted) return;
                    this.booted = true;
                    if (!Motion || !window.L) {
                        this.updateDelayed = true;
                        return;
                    }
                    this.bootMap();
                    this.refresh();
                    this.timer = setInterval(() => this.refresh(), this.pollSeconds * 1000);
                    this.clock = setInterval(() => { this.now = Date.now(); }, 1000);
                },
                stop() {
                    clearInterval(this.timer);
                    clearInterval(this.clock);
                    if (this.raf) cancelAnimationFrame(this.raf);
                    this.raf = null;
                },
                bootMap() {
                    if (this.map || !window.L) return;
                    const element = document.getElementById('live-tracking-map');
                    if (!element || element._leaflet_id) return;
                    this.map = window.L.map(element, { zoomControl: true }).setView([20.5937, 78.9629], 5);
                    window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap',
                    }).addTo(this.map);
                    this.cluster = window.L.layerGroup().addTo(this.map);
                    this.map.on('dragstart', () => { this.followPaused = true; });
                },
                async refresh() {
                    if (this.busy) return;
                    this.busy = true;
                    let delayed = false;
                    try {
                        const response = await fetch(this.snapshotUrl, {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) throw new Error('Live tracking refresh failed');
                        const data = await response.json();
                        this.summary = data.summary || {};
                        this.employees = (data.employees || []).map((employee) => ({
                            ...employee,
                            employee_url: `${this.employeeUrl}/${employee.employee_id}`,
                        }));
                        this.routeHandledMotion = false;
                        this.syncEmployees();
                        if (this.selectedId) {
                            const selected = this.employees.find((item) => item.employee_id === this.selectedId);
                            if (selected) {
                                this.routeEmployee = selected;
                                try {
                                    await this.refreshRoute();
                                } catch (error) {
                                    delayed = true;
                                }
                            } else {
                                this.clearRoute();
                            }
                        }
                        this.syncMotionsFromSnapshot();
                        this.maybeInitialFit();
                        this.updateDelayed = delayed;
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
                syncEmployees() {
                    if (!this.map) return;
                    const seen = new Set();
                    this.employees.forEach((employee) => {
                        if (employee.latitude == null || employee.longitude == null) return;
                        seen.add(employee.employee_id);
                        this.paintMarker(employee);
                    });
                    Object.keys(this.markers).forEach((id) => {
                        if (!seen.has(Number(id))) {
                            this.cluster.removeLayer(this.markers[id]);
                            delete this.markers[id];
                            delete this.motions[id];
                        }
                    });
                },
                paintMarker(employee) {
                    const id = employee.employee_id;
                    let marker = this.markers[id];
                    if (!marker) {
                        const point = this.samplePosition(id) || { lat: Number(employee.latitude), lng: Number(employee.longitude) };
                        marker = window.L.marker([point.lat, point.lng], { icon: this.iconFor(employee) });
                        marker.addTo(this.cluster);
                        marker.on('click', () => this.onMarkerClick(id));
                        this.markers[id] = marker;
                    } else {
                        this.updateMarkerFace(marker, employee);
                    }
                    marker.employee = employee;
                    marker.setZIndexOffset(this.selectedId === id ? 800 : 0);
                },
                iconFor(employee) {
                    const bearing = this.markers[employee.employee_id]?.__bearing ?? null;
                    return window.L.divIcon({
                        className: 'leaflet-div-icon emp-pin-icon',
                        html: Motion.markerHtml({ ...employee, bearing }, this.selectedId === employee.employee_id),
                        iconSize: [156, 86],
                        iconAnchor: [78, 26],
                    });
                },
                updateMarkerFace(marker, employee) {
                    const root = marker.getElement()?.querySelector('.emp-pin');
                    if (!root) {
                        marker.setIcon(this.iconFor(employee));
                        return;
                    }
                    const hasPhoto = !!root.querySelector('.emp-pin-photo');
                    if (!!employee.profile_photo_url !== hasPhoto) {
                        marker.setIcon(this.iconFor(employee));
                        return;
                    }
                    root.classList.toggle('is-current', this.selectedId === employee.employee_id);
                    root.dataset.status = Motion.markerTone(employee.status);
                    const name = root.querySelector('.emp-pin-name');
                    if (name) name.textContent = employee.employee_name || 'Employee';
                    const letters = root.querySelector('.emp-pin-initials');
                    if (letters) letters.textContent = employee.initials || Motion.initials(employee.employee_name);
                    const image = root.querySelector('.emp-pin-photo');
                    if (image && employee.profile_photo_url && image.getAttribute('src') !== employee.profile_photo_url) {
                        image.setAttribute('src', employee.profile_photo_url);
                    }
                    if (employee.status !== 'moving') this.applyHeading(marker, marker.__bearing, false);
                },
                refreshFaces() {
                    this.employees.forEach((employee) => {
                        const marker = this.markers[employee.employee_id];
                        if (marker) this.updateMarkerFace(marker, employee);
                    });
                },
                onMarkerClick(id) {
                    const employee = this.employees.find((item) => item.employee_id === id) || this.markers[id]?.employee;
                    if (!employee) return;
                    if (this.selectedId !== employee.employee_id) this.focusEmployee(employee);
                    const marker = this.markers[id];
                    if (marker) this.openPopup(employee, marker);
                },
                openPopup(employee, marker) {
                    marker.unbindPopup();
                    marker.bindPopup(Motion.popupHtml(employee, {
                        status: Motion.popupStatus(employee.status),
                        updated: this.updatedLabel(employee.recorded_at),
                        location: this.locationLabel(employee),
                        km: this.kmLabel(employee.today_distance_km),
                    }), { maxWidth: 260, className: 'emp-popup-wrap' }).openPopup();
                },
                focusEmployee(employee) {
                    if (!employee) return;
                    const previousId = this.selectedId;
                    const switching = Motion.shouldReloadRoute(previousId, employee.employee_id);
                    if (switching && this.motions[previousId]) this.motions[previousId].extendRoute = false;
                    this.selectedId = employee.employee_id;
                    this.followId = employee.employee_id;
                    this.followPaused = false;
                    this.routeEmployee = employee;
                    if (switching) {
                        this.latestPointId = null;
                        this.routeBootstrapped = false;
                        this.routeTrack = null;
                        this.clearRouteLayers();
                    }
                    this.refreshFaces();
                    const point = this.samplePosition(employee.employee_id)
                        || (employee.latitude == null ? null : { lat: Number(employee.latitude), lng: Number(employee.longitude) });
                    if (point && this.map) {
                        const zoom = Math.max(this.map.getZoom() || 5, 16);
                        this.map.flyTo([point.lat, point.lng], zoom, { duration: 0.8 });
                    }
                    this.refreshRoute();
                },
                async refreshRoute() {
                    if (!this.selectedId) return;
                    const requestId = (this.routeRequest || 0) + 1;
                    this.routeRequest = requestId;
                    const employeeId = this.selectedId;
                    let url = `${this.routeBase}/${employeeId}/route`;
                    if (this.latestPointId) url += `?after_point_id=${this.latestPointId}`;
                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!response.ok) throw new Error('Live route refresh failed');
                    const data = await response.json();
                    if (requestId !== this.routeRequest || employeeId !== this.selectedId) return;
                    this.applyRoutePayload(data, employeeId);
                },
                applyRoutePayload(data, employeeId) {
                    const employee = this.employees.find((item) => item.employee_id === employeeId) || this.routeEmployee;
                    this.routeMeta = {
                        punch_in_label: data.punch_in?.label,
                        working_minutes: data.working_minutes,
                        today_distance_km: data.today_distance_km,
                        recorded_at: employee?.recorded_at,
                    };
                    if (!this.routeBootstrapped) {
                        this.installFullRoute(data, employeeId);
                        this.routeBootstrapped = true;
                        if ((data.points || []).length) this.routeHandledMotion = true;
                        return;
                    }
                    const previous = this.routeTrack?.coords?.length
                        ? { lat: this.routeTrack.coords[this.routeTrack.coords.length - 1][0], lng: this.routeTrack.coords[this.routeTrack.coords.length - 1][1] }
                        : this.samplePosition(employeeId);
                    const accepted = Motion.acceptPoints(this.routeTrack?.knownIds || [], previous, data.points || []);
                    if (!this.routeTrack) this.routeTrack = { employeeId, coords: [], knownIds: [] };
                    this.routeTrack.knownIds = accepted.ids;
                    this.routeTrack.employeeId = employeeId;
                    if (accepted.fresh.length) {
                        this.enqueueMotion(employeeId, accepted.fresh, {
                            extendRoute: true,
                            follow: this.followId === employeeId && !this.followPaused,
                        });
                        this.routeHandledMotion = true;
                    }
                    if (data.latest_point_id) this.latestPointId = data.latest_point_id;
                },
                installFullRoute(data, employeeId) {
                    if (!this.map) return;
                    this.clearRouteLayers();
                    if (data.punch_in?.latitude != null && data.punch_in?.longitude != null) {
                        this.punchMarker = window.L.circleMarker([data.punch_in.latitude, data.punch_in.longitude], {
                            radius: 7, color: '#111827', fillColor: '#fbbf24', fillOpacity: 1,
                        }).addTo(this.map).bindTooltip('Punch in');
                    }
                    this.stopMarkers = [];
                    (data.stops || []).forEach((stop) => {
                        if (stop.latitude == null || stop.longitude == null) return;
                        this.stopMarkers.push(window.L.circleMarker([stop.latitude, stop.longitude], {
                            radius: 5, color: '#9a3412', fillColor: '#fdba74', fillOpacity: .9,
                        }).addTo(this.map).bindTooltip('Stop'));
                    });
                    const accepted = Motion.acceptPoints([], null, data.points || []);
                    this.routeTrack = {
                        employeeId,
                        coords: accepted.fresh.map((point) => [point.lat, point.lng]),
                        knownIds: accepted.ids,
                    };
                    if (this.routeTrack.coords.length) {
                        this.routeLine = window.L.polyline(this.routeTrack.coords, { color: '#d97706', weight: 4 }).addTo(this.map);
                    }
                    const last = accepted.fresh[accepted.fresh.length - 1];
                    if (last) {
                        this.snapMarker(employeeId, last);
                        delete this.motions[employeeId];
                    }
                    if (data.latest_point_id) this.latestPointId = data.latest_point_id;
                },
                syncMotionsFromSnapshot() {
                    this.employees.forEach((employee) => {
                        if (employee.latitude == null || employee.longitude == null) return;
                        if (employee.employee_id === this.selectedId && this.routeHandledMotion) return;
                        this.enqueueMotion(employee.employee_id, [{
                            lat: Number(employee.latitude),
                            lng: Number(employee.longitude),
                            id: null,
                        }], {
                            extendRoute: false,
                            follow: this.followId === employee.employee_id && !this.followPaused,
                        });
                    });
                },
                enqueueMotion(id, points, options = {}) {
                    const now = performance.now();
                    this.commitFinished(id, now);
                    const existing = this.motions[id];
                    const pending = existing
                        ? existing.segments.slice(existing.committedCount).map((segment) => segment.to)
                        : [];
                    const merged = Motion.dedupePath([...pending, ...points]);
                    if (!merged.length) return;
                    if (existing && Motion.samePath(pending, merged)) return;
                    const from = this.samplePosition(id);
                    const animate = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
                    if (!from) {
                        this.finishPath(id, merged, options);
                        return;
                    }
                    const plan = Motion.planMotion(from, merged, now, this.pollSeconds * 1000, animate);
                    if (plan.mode !== 'animate') {
                        if (plan.reason === 'teleport' && this.markers[id]) this.markers[id].__bearing = null;
                        this.finishPath(id, merged, options);
                        if (plan.reason !== 'teleport') {
                            const bearing = this.markers[id]?.__bearing;
                            if (bearing != null) this.applyHeading(this.markers[id], bearing, this.markers[id]?.employee?.status === 'moving');
                        }
                        return;
                    }
                    this.motions[id] = {
                        ...plan,
                        extendRoute: !!options.extendRoute,
                        committedCount: 0,
                        employeeId: Number(id),
                    };
                    if (options.follow) this.maybeFollow(id, plan.destination);
                    this.ensureAnimation();
                },
                finishPath(id, points, options) {
                    const last = points[points.length - 1];
                    this.snapMarker(id, last);
                    delete this.motions[id];
                    if (options.extendRoute) points.forEach((point) => this.pushRouteCoord(point));
                    if (options.follow) this.maybeFollow(id, last);
                },
                commitFinished(id, now) {
                    const motion = this.motions[id];
                    if (!motion) return;
                    const sample = Motion.positionAt(motion, now);
                    if (!sample) return;
                    while (motion.committedCount < sample.completedCount) {
                        const segment = motion.segments[motion.committedCount];
                        if (motion.extendRoute) this.pushRouteCoord(segment.to);
                        motion.committedCount += 1;
                    }
                    if (sample.done) delete this.motions[id];
                },
                snapMarker(id, point) {
                    if (!point) return;
                    const employee = this.employees.find((item) => item.employee_id === id);
                    if (!this.markers[id] && employee) this.paintMarker(employee);
                    const marker = this.markers[id];
                    if (!marker) return;
                    marker.setLatLng([point.lat, point.lng]);
                },
                samplePosition(id) {
                    const motion = this.motions[id];
                    if (motion) {
                        const sample = Motion.positionAt(motion, performance.now());
                        if (sample) return { lat: sample.lat, lng: sample.lng };
                    }
                    const marker = this.markers[id];
                    if (!marker) return null;
                    const latlng = marker.getLatLng();
                    return { lat: latlng.lat, lng: latlng.lng };
                },
                pushRouteCoord(point) {
                    if (!this.routeTrack || this.routeTrack.employeeId !== this.selectedId || !point) return;
                    const last = this.routeTrack.coords[this.routeTrack.coords.length - 1];
                    if (last && Math.abs(last[0] - point.lat) < 1e-7 && Math.abs(last[1] - point.lng) < 1e-7) return;
                    this.routeTrack.coords.push([point.lat, point.lng]);
                    this.syncRouteLine(null);
                },
                syncRouteLine(current) {
                    if (!this.map || !this.routeTrack || this.routeTrack.employeeId !== this.selectedId) return;
                    const latlngs = Motion.liveTail(this.routeTrack.coords, current);
                    if (!latlngs.length) return;
                    if (!this.routeLine) {
                        this.routeLine = window.L.polyline(latlngs, { color: '#d97706', weight: 4 }).addTo(this.map);
                    } else {
                        this.routeLine.setLatLngs(latlngs);
                    }
                },
                ensureAnimation() {
                    if (this.raf != null) return;
                    this.raf = requestAnimationFrame(() => this.tick());
                },
                tick() {
                    const now = performance.now();
                    let active = false;
                    Object.keys(this.motions).forEach((id) => {
                        const motion = this.motions[id];
                        const marker = this.markers[id];
                        if (!motion || !marker) return;
                        const sample = Motion.positionAt(motion, now);
                        if (!sample) return;
                        while (motion.committedCount < sample.completedCount) {
                            const segment = motion.segments[motion.committedCount];
                            if (motion.extendRoute) this.pushRouteCoord(segment.to);
                            motion.committedCount += 1;
                        }
                        marker.setLatLng([sample.lat, sample.lng]);
                        const moving = marker.employee?.status === 'moving' && sample.bearing != null;
                        this.applyHeading(marker, sample.bearing, moving);
                        if (motion.extendRoute) this.syncRouteLine(sample.done ? null : sample);
                        if (sample.done) delete this.motions[id];
                        else active = true;
                    });
                    this.raf = active ? requestAnimationFrame(() => this.tick()) : null;
                },
                applyHeading(marker, bearing, show) {
                    if (!marker) return;
                    const heading = marker.getElement()?.querySelector('.emp-pin-heading');
                    if (!heading) return;
                    if (bearing != null && !Number.isNaN(Number(bearing))) {
                        marker.__bearing = bearing;
                        heading.style.transform = `rotate(${bearing}deg)`;
                    }
                    heading.style.display = show ? 'block' : 'none';
                },
                maybeFollow(id, target) {
                    if (!this.map || this.followId !== id || this.followPaused || !target) return;
                    const latlng = window.L.latLng(target.lat, target.lng);
                    if (this.map.getCenter().distanceTo(latlng) < 25) return;
                    this.map.panTo(latlng, { animate: true, duration: 0.8 });
                },
                clearRoute() {
                    const previousId = this.selectedId;
                    if (previousId && this.motions[previousId]) this.motions[previousId].extendRoute = false;
                    this.routeEmployee = null;
                    this.selectedId = null;
                    this.followId = null;
                    this.followPaused = false;
                    this.latestPointId = null;
                    this.routeBootstrapped = false;
                    this.routeTrack = null;
                    this.routeHandledMotion = false;
                    this.clearRouteLayers();
                    this.refreshFaces();
                    this.fitEmployees();
                },
                clearRouteLayers() {
                    if (!this.map) {
                        this.routeLine = null;
                        this.punchMarker = null;
                        this.stopMarkers = [];
                        return;
                    }
                    if (this.routeLine) this.map.removeLayer(this.routeLine);
                    if (this.punchMarker) this.map.removeLayer(this.punchMarker);
                    this.stopMarkers.forEach((marker) => this.map.removeLayer(marker));
                    this.routeLine = null;
                    this.punchMarker = null;
                    this.stopMarkers = [];
                },
                viewAll() {
                    this.followPaused = true;
                    this.initialFitDone = true;
                    this.fitEmployees();
                },
                maybeInitialFit() {
                    if (this.initialFitDone || this.followId || this.selectedId) return;
                    if (Object.keys(this.markers).length === 0) return;
                    this.initialFitDone = true;
                    this.fitEmployees();
                },
                fitEmployees() {
                    const positions = Object.values(this.markers).map((marker) => marker.getLatLng());
                    if (positions.length === 0 || !this.map) return;
                    this.map.fitBounds(window.L.latLngBounds(positions), { padding: [32, 32], maxZoom: 15 });
                },
                statusLabel(status) {
                    return {
                        moving: 'Moving',
                        stopped: 'Stopped',
                        delayed: 'Location delayed',
                        offline: 'Offline / No recent GPS',
                        no_gps: 'No GPS Yet',
                    }[status] || status;
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
                locationLabel(employee) {
                    if (employee.latitude == null || employee.longitude == null) return 'Waiting for GPS';
                    return `${Number(employee.latitude).toFixed(5)}, ${Number(employee.longitude).toFixed(5)}`;
                },
                updatedLabel(value) {
                    if (!value) return 'No GPS yet';
                    const date = new Date(value);
                    if (Number.isNaN(date.getTime())) return this.relative(value);
                    const clock = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                    return `${clock} (${this.relative(value)})`;
                },
                relative(value) {
                    if (!value) return 'No GPS yet';
                    const seconds = Math.max(0, Math.round((this.now - new Date(value).getTime()) / 1000));
                    if (seconds < 60) return `${seconds} sec ago`;
                    const minutes = Math.round(seconds / 60);
                    return `${minutes} min ago`;
                },
                lastUpdatedLabel() {
                    if (!this.summary.last_updated_at) return 'Waiting for GPS';
                    return this.relative(this.summary.last_updated_at);
                },
                escape(value) {
                    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
                },
            };
        }
    </script>
</x-filament-panels::page>
