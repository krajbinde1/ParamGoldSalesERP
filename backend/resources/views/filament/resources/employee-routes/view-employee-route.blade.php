@php
    $routeData = $this->getRouteMapData();
    $hasEnoughPoints = ($routeData['summary']['valid_point_count'] ?? 0) >= 2;
    $mapElementId = 'employee-route-map-'.$this->getRecord()->getKey();
    $diagnostics = $routeData['diagnostics'] ?? [];
    $sparseWarning = $diagnostics['sparse_warning'] ?? null;
    $timeline = $routeData['timeline'] ?? $routeData['route_points'] ?? [];
    $employeeName = $routeData['employee']['full_name'] ?? 'Employee';
    $employeeCode = $routeData['employee']['employee_code'] ?? null;
    $attendanceDate = $routeData['attendance_date'] ?? '-';
    $stopCount = (int) ($routeData['summary']['stop_count'] ?? 0);
    $validPoints = (int) ($routeData['summary']['valid_point_count'] ?? 0);
    $distanceKm = number_format((float) ($routeData['summary']['total_distance_km'] ?? 0), 2);
@endphp

@push('styles')
    @if ($hasEnoughPoints)
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        />
    @endif
    <style>
        .employee-route-page {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .employee-route-map-shell {
            width: 100%;
            margin: 0;
            border-radius: 0.75rem;
            overflow: hidden;
            border: 1px solid rgb(229 231 235);
            background: rgb(249 250 251);
        }

        .dark .employee-route-map-shell {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        #{{ $mapElementId }} {
            width: 100%;
            height: clamp(420px, 70vh, 900px);
            min-height: 600px;
            position: relative;
            z-index: 0;
        }

        @media (max-width: 768px) {
            #{{ $mapElementId }} {
                height: clamp(320px, 55vh, 560px);
                min-height: 320px;
            }
        }

        #{{ $mapElementId }} .leaflet-container {
            width: 100% !important;
            height: 100% !important;
            z-index: 0;
            font: inherit;
        }

        .employee-route-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.25rem;
            align-items: center;
        }

        .employee-route-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: rgb(75 85 99);
        }

        .dark .employee-route-legend-item {
            color: rgb(209 213 219);
        }

        .employee-route-legend-swatch {
            width: 0.875rem;
            height: 0.875rem;
            border-radius: 9999px;
            border: 2px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
        }

        .employee-route-legend-line {
            width: 1.25rem;
            height: 0;
            border-top: 3px solid #2563eb;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .employee-route-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .employee-route-summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .employee-route-summary-card {
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
            background: white;
            padding: 0.75rem 1rem;
            min-width: 0;
        }

        .dark .employee-route-summary-card {
            border-color: rgb(55 65 81);
            background: rgb(17 24 39);
        }

        .employee-route-summary-label {
            font-size: 0.75rem;
            font-weight: 500;
            color: rgb(107 114 128);
        }

        .dark .employee-route-summary-label {
            color: rgb(156 163 175);
        }

        .employee-route-summary-value {
            margin-top: 0.25rem;
            font-size: 0.9375rem;
            font-weight: 600;
            color: rgb(17 24 39);
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dark .employee-route-summary-value {
            color: white;
        }

        .employee-route-timeline-wrap {
            max-height: min(28rem, 50vh);
            overflow: auto;
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
        }

        .dark .employee-route-timeline-wrap {
            border-color: rgb(55 65 81);
        }

        .employee-route-timeline-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgb(249 250 251);
        }

        .dark .employee-route-timeline-wrap thead th {
            background: rgb(17 24 39);
        }
    </style>
@endpush

@if ($hasEnoughPoints)
    @push('scripts')
        <script
            src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""
        ></script>
    @endpush
@endif

<x-filament-panels::page>
    <div class="employee-route-page space-y-4">
        @if (filled($sparseWarning))
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                {{ $sparseWarning }}
            </div>
        @endif

        <div class="employee-route-summary-grid">
            <div class="employee-route-summary-card" title="{{ $employeeName }}{{ filled($employeeCode) ? ' ('.$employeeCode.')' : '' }}">
                <div class="employee-route-summary-label">Employee</div>
                <div class="employee-route-summary-value">
                    {{ $employeeName }}{{ filled($employeeCode) ? ' ('.$employeeCode.')' : '' }}
                </div>
            </div>
            <div class="employee-route-summary-card">
                <div class="employee-route-summary-label">Attendance Date</div>
                <div class="employee-route-summary-value">{{ $attendanceDate }}</div>
            </div>
            <div class="employee-route-summary-card">
                <div class="employee-route-summary-label">Total Distance</div>
                <div class="employee-route-summary-value">{{ $distanceKm }} km</div>
            </div>
            <div class="employee-route-summary-card">
                <div class="employee-route-summary-label">Stops / Valid Points</div>
                <div class="employee-route-summary-value">{{ $stopCount }} Stops / {{ $validPoints }} Valid Points</div>
            </div>
        </div>

        <section class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Route Map
            </h3>

            @if ($hasEnoughPoints)
                <div class="employee-route-map-shell">
                    <div id="{{ $mapElementId }}" wire:ignore></div>
                </div>

                <div class="employee-route-legend rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="employee-route-legend-item">
                        <span class="employee-route-legend-swatch" style="background:#16a34a;"></span>
                        <span>Punch In</span>
                    </div>
                    <div class="employee-route-legend-item">
                        <span class="employee-route-legend-swatch" style="background:#dc2626;"></span>
                        <span>Punch Out</span>
                    </div>
                    <div class="employee-route-legend-item">
                        <span class="employee-route-legend-swatch" style="background:#ea580c;"></span>
                        <span>Stop</span>
                    </div>
                    <div class="employee-route-legend-item">
                        <span class="employee-route-legend-line" aria-hidden="true"></span>
                        <span>Route</span>
                    </div>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-gray-300 px-6 py-16 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    Not enough valid route points to draw a map. At least 2 valid points are required.
                    @if (($diagnostics['total_points'] ?? 0) > 0)
                        <div class="mt-2 text-amber-700 dark:text-amber-300">
                            Incomplete route data – only {{ $diagnostics['total_points'] }} GPS points were received from the mobile device.
                        </div>
                    @endif
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 sm:p-5">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Route Timeline</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ count($timeline) }} points
                    @if (($diagnostics['rejected_count'] ?? 0) > 0)
                        · {{ $diagnostics['rejected_count'] }} rejected
                    @endif
                </p>
            </div>

            <div class="employee-route-timeline-wrap overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2.5">Time</th>
                            <th class="px-3 py-2.5">Latitude</th>
                            <th class="px-3 py-2.5">Longitude</th>
                            <th class="px-3 py-2.5">Accuracy</th>
                            <th class="px-3 py-2.5">Event / Type</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($timeline as $point)
                            <tr class="text-gray-800 dark:text-gray-200">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($point['recorded_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap font-mono text-xs sm:text-sm">
                                    {{ number_format((float) $point['latitude'], 6) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap font-mono text-xs sm:text-sm">
                                    {{ number_format((float) $point['longitude'], 6) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ isset($point['accuracy']) && $point['accuracy'] !== null ? number_format((float) $point['accuracy'], 1).' m' : '—' }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    @php $type = $point['point_type'] ?? 'Route Point'; @endphp
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-0.5 text-xs font-medium',
                                        'bg-green-50 text-green-800 dark:bg-green-500/10 dark:text-green-300' => $type === 'Punch In',
                                        'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-300' => $type === 'Punch Out',
                                        'bg-orange-50 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300' => str_contains(strtolower((string) $type), 'stop'),
                                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! in_array($type, ['Punch In', 'Punch Out'], true) && ! str_contains(strtolower((string) $type), 'stop'),
                                    ])>
                                        {{ $type }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No route points recorded.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if (($routeData['stops'] ?? []) !== [])
            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 sm:p-5">
                <h3 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">Detected Stops</h3>
                <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-gray-800">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-950/50">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-3 py-2.5">#</th>
                                <th class="px-3 py-2.5">Start</th>
                                <th class="px-3 py-2.5">End</th>
                                <th class="px-3 py-2.5">Duration</th>
                                <th class="px-3 py-2.5">Centre</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($routeData['stops'] as $index => $stop)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $index + 1 }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($stop['start_time'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ \Illuminate\Support\Carbon::parse($stop['end_time'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $stop['duration_minutes'] }} min</td>
                                    <td class="px-3 py-2 whitespace-nowrap font-mono text-xs sm:text-sm">
                                        {{ number_format((float) $stop['latitude'], 6) }}, {{ number_format((float) $stop['longitude'], 6) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    @if ($hasEnoughPoints)
        @script
            <script>
                (() => {
                    const mapElementId = @js($mapElementId);
                    const routeData = @json($routeData, JSON_THROW_ON_ERROR);

                    window.__employeeRouteMapInstances = window.__employeeRouteMapInstances || {};

                    const toNumber = (value) => {
                        const parsed = Number.parseFloat(value);
                        return Number.isFinite(parsed) ? parsed : null;
                    };

                    const toLatLng = (latitude, longitude) => {
                        const lat = toNumber(latitude);
                        const lng = toNumber(longitude);

                        if (lat === null || lng === null) {
                            return null;
                        }

                        return [lat, lng];
                    };

                    const destroyMap = () => {
                        const existing = window.__employeeRouteMapInstances[mapElementId];

                        if (existing) {
                            existing.remove();
                            delete window.__employeeRouteMapInstances[mapElementId];
                        }
                    };

                    const fitRouteBounds = (map, bounds) => {
                        if (!map || !bounds || bounds.length === 0) {
                            return;
                        }

                        const latLngBounds = window.L.latLngBounds(bounds);

                        if (!latLngBounds.isValid()) {
                            return;
                        }

                        map.fitBounds(latLngBounds, {
                            padding: [56, 56],
                            maxZoom: 16,
                            animate: false,
                        });
                    };

                    const invalidateAndRefit = (map, bounds) => {
                        if (!map) {
                            return;
                        }

                        map.invalidateSize({ animate: false, pan: false });
                        fitRouteBounds(map, bounds);
                    };

                    const waitForLeaflet = (attempt = 0) => {
                        if (typeof window.L !== 'undefined') {
                            return Promise.resolve(window.L);
                        }

                        if (attempt >= 40) {
                            return Promise.reject(new Error('Leaflet failed to load.'));
                        }

                        return new Promise((resolve) => {
                            window.setTimeout(() => {
                                resolve(waitForLeaflet(attempt + 1));
                            }, 100);
                        });
                    };

                    const markerIcon = (color) => {
                        return window.L.divIcon({
                            className: '',
                            html: `<span style="
                                display:block;
                                width:16px;
                                height:16px;
                                border-radius:9999px;
                                background:${color};
                                border:2px solid #fff;
                                box-shadow:0 1px 4px rgba(0,0,0,.35);
                            "></span>`,
                            iconSize: [16, 16],
                            iconAnchor: [8, 8],
                            popupAnchor: [0, -8],
                        });
                    };

                    const renderMap = () => {
                        const mapElement = document.getElementById(mapElementId);

                        if (!mapElement) {
                            return false;
                        }

                        if (mapElement.offsetWidth < 40 || mapElement.offsetHeight < 40) {
                            return false;
                        }

                        destroyMap();

                        const map = window.L.map(mapElement, {
                            preferCanvas: true,
                            zoomControl: true,
                        });

                        window.__employeeRouteMapInstances[mapElementId] = map;

                        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19,
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                        }).addTo(map);

                        const bounds = [];

                        const pushBound = (latLng) => {
                            if (latLng) {
                                bounds.push(latLng);
                            }
                        };

                        const pathSource = (routeData.valid_points && routeData.valid_points.length >= 2)
                            ? routeData.valid_points
                            : (routeData.timeline || []);

                        const polylinePoints = pathSource
                            .map((point) => toLatLng(point.latitude, point.longitude))
                            .filter((latLng) => latLng !== null);

                        polylinePoints.forEach(pushBound);

                        if (polylinePoints.length >= 2) {
                            window.L.polyline(polylinePoints, {
                                color: '#2563eb',
                                weight: 5,
                                opacity: 0.9,
                                lineJoin: 'round',
                                lineCap: 'round',
                            }).addTo(map);
                        }

                        const addEventMarker = (latitude, longitude, color, html) => {
                            const latLng = toLatLng(latitude, longitude);

                            if (!latLng) {
                                return;
                            }

                            const marker = window.L.marker(latLng, {
                                icon: markerIcon(color),
                                zIndexOffset: (color === '#16a34a' || color === '#dc2626') ? 600 : 400,
                            }).addTo(map);

                            marker.bindPopup(html);
                            pushBound(latLng);
                        };

                        const punchIn = routeData.punch_in || {};
                        const punchOut = routeData.punch_out || {};

                        if (punchIn.latitude != null && punchIn.longitude != null) {
                            addEventMarker(
                                punchIn.latitude,
                                punchIn.longitude,
                                '#16a34a',
                                `<strong>Punch In</strong><br>${punchIn.time ?? '-'}`,
                            );
                        } else {
                            const pin = (routeData.timeline || []).find((p) => p.point_type === 'Punch In');
                            if (pin) {
                                addEventMarker(pin.latitude, pin.longitude, '#16a34a', `<strong>Punch In</strong><br>${pin.recorded_at ?? '-'}`);
                            }
                        }

                        if (punchOut.latitude != null && punchOut.longitude != null) {
                            addEventMarker(
                                punchOut.latitude,
                                punchOut.longitude,
                                '#dc2626',
                                `<strong>Punch Out</strong><br>${punchOut.time ?? '-'}`,
                            );
                        } else {
                            const pout = (routeData.timeline || []).find((p) => p.point_type === 'Punch Out');
                            if (pout) {
                                addEventMarker(pout.latitude, pout.longitude, '#dc2626', `<strong>Punch Out</strong><br>${pout.recorded_at ?? '-'}`);
                            }
                        }

                        (routeData.stops || []).forEach((stop, index) => {
                            addEventMarker(
                                stop.latitude,
                                stop.longitude,
                                '#ea580c',
                                `<strong>Stop ${index + 1}</strong><br>${stop.start_time ?? '-'} → ${stop.end_time ?? '-'}<br>${stop.duration_minutes ?? 0} min`,
                            );
                        });

                        if (bounds.length > 0) {
                            fitRouteBounds(map, bounds);
                        } else {
                            map.setView([20.5937, 78.9629], 5);
                        }

                        window.requestAnimationFrame(() => {
                            invalidateAndRefit(map, bounds);
                            window.setTimeout(() => invalidateAndRefit(map, bounds), 120);
                            window.setTimeout(() => invalidateAndRefit(map, bounds), 400);
                            window.setTimeout(() => invalidateAndRefit(map, bounds), 900);
                        });

                        return true;
                    };

                    const initializeMap = () => {
                        waitForLeaflet()
                            .then(() => {
                                if (!renderMap()) {
                                    window.setTimeout(initializeMap, 150);
                                }
                            })
                            .catch((error) => {
                                console.error('[EmployeeRouteMap]', error);
                            });
                    };

                    const scheduleInitialize = () => {
                        window.requestAnimationFrame(() => {
                            window.setTimeout(initializeMap, 30);
                        });
                    };

                    scheduleInitialize();

                    document.addEventListener('livewire:navigated', scheduleInitialize);
                    document.addEventListener('DOMContentLoaded', scheduleInitialize);

                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState !== 'visible') {
                            return;
                        }

                        const map = window.__employeeRouteMapInstances[mapElementId];
                        if (map) {
                            map.invalidateSize({ animate: false, pan: false });
                        }
                    });

                    window.addEventListener('resize', () => {
                        const map = window.__employeeRouteMapInstances[mapElementId];
                        if (map) {
                            map.invalidateSize({ animate: false, pan: false });
                        }
                    });

                    const mapElement = document.getElementById(mapElementId);

                    if (mapElement && typeof ResizeObserver !== 'undefined') {
                        const observer = new ResizeObserver(() => {
                            const map = window.__employeeRouteMapInstances[mapElementId];
                            if (map) {
                                map.invalidateSize({ animate: false, pan: false });
                            }
                        });
                        observer.observe(mapElement);
                    }
                })();
            </script>
        @endscript
    @endif
</x-filament-panels::page>
