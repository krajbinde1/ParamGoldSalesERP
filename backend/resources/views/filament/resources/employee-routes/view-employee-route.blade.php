@php
    $routeData = $this->getRouteMapData();
    $hasEnoughPoints = ($routeData['summary']['valid_point_count'] ?? 0) >= 2;
    $mapElementId = 'employee-route-map-'.$this->getRecord()->getKey();
    $diagnostics = $routeData['diagnostics'] ?? [];
    $sparseWarning = $diagnostics['sparse_warning'] ?? null;
    $timeline = $routeData['timeline'] ?? $routeData['route_points'] ?? [];
@endphp

@if ($hasEnoughPoints)
    @push('styles')
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        />
        <style>
            #{{ $mapElementId }} {
                width: 100%;
                min-height: 450px;
                height: 450px;
                position: relative;
                z-index: 0;
            }

            #{{ $mapElementId }} .leaflet-container {
                width: 100%;
                height: 100%;
                z-index: 0;
            }
        </style>
    @endpush

    @push('scripts')
        <script
            src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""
        ></script>
    @endpush
@endif

<x-filament-panels::page>
    <div class="space-y-6">
        @if (filled($sparseWarning))
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                {{ $sparseWarning }}
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Employee</div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                    {{ $routeData['employee']['full_name'] ?? '-' }}
                    @if (filled($routeData['employee']['employee_code'] ?? null))
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">({{ $routeData['employee']['employee_code'] }})</span>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Attendance Date</div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">{{ $routeData['attendance_date'] }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Distance</div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                    {{ number_format($routeData['summary']['total_distance_km'], 2) }} km
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Valid Points / Stops</div>
                <div class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
                    {{ $routeData['summary']['valid_point_count'] }} points,
                    {{ $routeData['summary']['stop_count'] }} stops
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Route Diagnostics">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 text-sm">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Total GPS points</div>
                    <div class="font-semibold">{{ $diagnostics['total_points'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Valid points used</div>
                    <div class="font-semibold">{{ $diagnostics['valid_points_used'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Rejected points</div>
                    <div class="font-semibold">{{ $diagnostics['rejected_count'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Ignored segments</div>
                    <div class="font-semibold">{{ $diagnostics['ignored_segment_count'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">First point</div>
                    <div class="font-semibold">
                        {{ filled($diagnostics['first_recorded_at'] ?? null) ? \Illuminate\Support\Carbon::parse($diagnostics['first_recorded_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Last point</div>
                    <div class="font-semibold">
                        {{ filled($diagnostics['last_recorded_at'] ?? null) ? \Illuminate\Support\Carbon::parse($diagnostics['last_recorded_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Duration</div>
                    <div class="font-semibold">
                        {{ isset($diagnostics['duration_minutes']) ? $diagnostics['duration_minutes'].' min' : '-' }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Route Map">
            @if ($hasEnoughPoints)
                <div id="{{ $mapElementId }}"></div>

                <div class="mt-3 flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300">
                    <span><span class="inline-block h-3 w-3 rounded-full bg-green-500"></span> Punch In</span>
                    <span><span class="inline-block h-3 w-3 rounded-full bg-blue-500"></span> Route Point</span>
                    <span><span class="inline-block h-3 w-3 rounded-full bg-red-500"></span> Punch Out</span>
                    <span><span class="inline-block h-3 w-3 rounded-full bg-orange-500"></span> Stop</span>
                </div>
            @else
                <div class="rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    Not enough valid route points to draw a map. At least 2 valid points are required.
                    @if (($diagnostics['total_points'] ?? 0) > 0)
                        <div class="mt-2 text-amber-700 dark:text-amber-300">
                            Incomplete route data – only {{ $diagnostics['total_points'] }} GPS points were received from the mobile device.
                        </div>
                    @endif
                </div>
            @endif
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Route Timeline">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Time</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Lat</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Lng</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Accuracy</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Distance from Previous</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Point Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @forelse ($timeline as $point)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($point['recorded_at'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ number_format($point['latitude'], 6) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ number_format($point['longitude'], 6) }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ isset($point['accuracy']) && $point['accuracy'] !== null ? number_format($point['accuracy'], 1).' m' : '-' }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if (isset($point['distance_from_previous_m']) && $point['distance_from_previous_m'] !== null)
                                            {{ number_format($point['distance_from_previous_m'], 1) }} m
                                            ({{ number_format($point['distance_from_previous_km'] ?? ($point['distance_from_previous_m'] / 1000), 3) }} km)
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{ $point['point_type'] ?? 'Route Point' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">No route points recorded.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section heading="Detected Stops">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Start</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">End</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Duration</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Centre</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @forelse ($routeData['stops'] as $stop)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($stop['start_time'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($stop['end_time'])->timezone('Asia/Kolkata')->format('d M Y h:i A') }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $stop['duration_minutes'] }} min</td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ number_format($stop['latitude'], 6) }}, {{ number_format($stop['longitude'], 6) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">No stops detected.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
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

                    const invalidateMapSize = () => {
                        const map = window.__employeeRouteMapInstances[mapElementId];

                        if (map) {
                            map.invalidateSize(true);
                        }
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

                    const renderMap = () => {
                        const mapElement = document.getElementById(mapElementId);

                        if (!mapElement) {
                            return false;
                        }

                        if (mapElement.offsetWidth === 0 || mapElement.offsetHeight === 0) {
                            return false;
                        }

                        destroyMap();

                        const map = window.L.map(mapElement, {
                            preferCanvas: true,
                        });

                        window.__employeeRouteMapInstances[mapElementId] = map;

                        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19,
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                        }).addTo(map);

                        const bounds = [];

                        const addMarker = (latitude, longitude, color, label, radius = 8) => {
                            const latLng = toLatLng(latitude, longitude);

                            if (!latLng) {
                                return;
                            }

                            const marker = window.L.circleMarker(latLng, {
                                radius,
                                color,
                                fillColor: color,
                                fillOpacity: 0.9,
                                weight: 2,
                            }).addTo(map);

                            marker.bindPopup(label);
                            bounds.push(latLng);
                        };

                        const timeline = (routeData.timeline || [])
                            .map((point) => ({
                                type: point.point_type || 'Route Point',
                                latitude: toNumber(point.latitude),
                                longitude: toNumber(point.longitude),
                                recorded_at: point.recorded_at,
                            }))
                            .filter((point) => point.latitude !== null && point.longitude !== null)
                            .sort((a, b) => String(a.recorded_at).localeCompare(String(b.recorded_at)));

                        const polylinePoints = timeline.map((point) => {
                            const latLng = [point.latitude, point.longitude];
                            bounds.push(latLng);
                            return latLng;
                        });

                        if (polylinePoints.length >= 2) {
                            window.L.polyline(polylinePoints, {
                                color: '#3b82f6',
                                weight: 4,
                                opacity: 0.85,
                            }).addTo(map);
                        }

                        timeline.forEach((point) => {
                            if (point.type === 'Punch In') {
                                addMarker(
                                    point.latitude,
                                    point.longitude,
                                    '#22c55e',
                                    `<strong>Punch In</strong><br>${point.recorded_at ?? '-'}`,
                                    9,
                                );
                            } else if (point.type === 'Punch Out') {
                                addMarker(
                                    point.latitude,
                                    point.longitude,
                                    '#ef4444',
                                    `<strong>Punch Out</strong><br>${point.recorded_at ?? '-'}`,
                                    9,
                                );
                            } else {
                                addMarker(
                                    point.latitude,
                                    point.longitude,
                                    '#3b82f6',
                                    `<strong>Route Point</strong><br>${point.recorded_at ?? '-'}`,
                                    4,
                                );
                            }
                        });

                        (routeData.stops || []).forEach((stop, index) => {
                            const latLng = toLatLng(stop.latitude, stop.longitude);

                            if (!latLng) {
                                return;
                            }

                            const marker = window.L.circleMarker(latLng, {
                                radius: 9,
                                color: '#f97316',
                                fillColor: '#f97316',
                                fillOpacity: 0.9,
                                weight: 2,
                            }).addTo(map);

                            bounds.push(latLng);

                            marker.bindPopup(
                                `<strong>Stop ${index + 1}</strong><br>${stop.start_time ?? '-'} to ${stop.end_time ?? '-'}<br>${stop.duration_minutes ?? 0} minutes`,
                            );
                        });

                        if (bounds.length > 0) {
                            map.fitBounds(bounds, { padding: [40, 40] });
                        } else {
                            map.setView([20.5937, 78.9629], 5);
                        }

                        window.setTimeout(() => invalidateMapSize(), 150);
                        window.setTimeout(() => invalidateMapSize(), 600);

                        return true;
                    };

                    const initializeMap = () => {
                        waitForLeaflet()
                            .then(() => {
                                if (!renderMap()) {
                                    window.setTimeout(initializeMap, 200);
                                }
                            })
                            .catch((error) => {
                                console.error('[EmployeeRouteMap]', error);
                            });
                    };

                    const scheduleInitialize = () => {
                        window.requestAnimationFrame(() => {
                            window.setTimeout(initializeMap, 50);
                        });
                    };

                    scheduleInitialize();

                    document.addEventListener('livewire:navigated', scheduleInitialize);
                    document.addEventListener('DOMContentLoaded', scheduleInitialize);

                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') {
                            invalidateMapSize();
                        }
                    });

                    const mapElement = document.getElementById(mapElementId);

                    if (mapElement && typeof ResizeObserver !== 'undefined') {
                        const observer = new ResizeObserver(() => invalidateMapSize());
                        observer.observe(mapElement);
                    }
                })();
            </script>
        @endscript
    @endif
</x-filament-panels::page>
