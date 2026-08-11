<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;

class EmployeeRouteAnalysisService
{
    public const SPARSE_ROUTE_POINT_THRESHOLD = 5;

    public function __construct(
        private readonly RouteDistanceCalculator $distanceCalculator,
        private readonly RouteStopDetector $stopDetector,
    ) {}

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     diagnostics: array<string, mixed>,
     *     valid_points: array<int, array<string, mixed>>,
     *     stops: array<int, array<string, mixed>>,
     *     journey_events: array<int, array<string, mixed>>,
     *     timeline: array<int, array<string, mixed>>
     * }
     */
    public function analyze(Attendance $attendance): array
    {
        $attendance->loadMissing([
            'routePoints' => fn ($query) => $query->orderBy('recorded_at')->orderBy('id'),
        ]);

        $distanceResult = $this->distanceCalculator->calculate($attendance->routePoints);
        $stops = $this->stopDetector->detect($distanceResult['valid_points']);
        $rawPointCount = $attendance->routePoints->count();
        $journeyEvents = $this->buildJourneyEvents($attendance, $distanceResult['valid_points'], $stops);

        $travelTimeMinutes = collect($journeyEvents)
            ->where('type', 'travel')
            ->sum(fn (array $event): int => (int) ($event['duration_minutes'] ?? 0));

        $stoppageTimeMinutes = collect($stops)->sum(fn (array $stop): int => (int) ($stop['duration_minutes'] ?? 0));

        $punchInAt = $attendance->punchInAt();
        $punchOutAt = $attendance->punchOutAt();

        $diagnostics = [
            'total_points' => $rawPointCount,
            'valid_points_used' => $distanceResult['valid_point_count'],
            'rejected_count' => $distanceResult['rejected_point_count'],
            'ignored_segment_count' => $distanceResult['ignored_segment_count'],
            'first_recorded_at' => $distanceResult['first_recorded_at'] instanceof Carbon
                ? $this->formatDateTime($distanceResult['first_recorded_at'])
                : null,
            'last_recorded_at' => $distanceResult['last_recorded_at'] instanceof Carbon
                ? $this->formatDateTime($distanceResult['last_recorded_at'])
                : null,
            'duration_minutes' => $distanceResult['duration_minutes'],
            'is_sparse' => $rawPointCount > 0 && $rawPointCount < self::SPARSE_ROUTE_POINT_THRESHOLD,
            'sparse_warning' => ($rawPointCount > 0 && $rawPointCount < self::SPARSE_ROUTE_POINT_THRESHOLD)
                ? sprintf(
                    'Incomplete route data – only %d GPS points were received from the mobile device.',
                    $rawPointCount,
                )
                : null,
        ];

        return [
            'summary' => [
                'total_distance_km' => $distanceResult['total_distance_km'],
                'valid_point_count' => $distanceResult['valid_point_count'],
                'ignored_segment_count' => $distanceResult['ignored_segment_count'],
                'rejected_point_count' => $distanceResult['rejected_point_count'],
                'stop_count' => count($stops),
                'total_points' => $rawPointCount,
                'travel_time_minutes' => (int) $travelTimeMinutes,
                'travel_time_label' => $this->formatDurationLabel((int) $travelTimeMinutes),
                'stoppage_time_minutes' => (int) $stoppageTimeMinutes,
                'punch_in_time' => $punchInAt?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'punch_out_time' => $punchOutAt?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'punch_in_at' => $punchInAt !== null ? $this->formatDateTime($punchInAt) : null,
                'punch_out_at' => $punchOutAt !== null ? $this->formatDateTime($punchOutAt) : null,
            ],
            'diagnostics' => $diagnostics,
            'valid_points' => $distanceResult['valid_points'],
            'stops' => $stops,
            'journey_events' => $journeyEvents,
            'timeline' => $this->buildTimeline($attendance, $distanceResult),
        ];
    }

    public function recalculateAndPersistDistance(Attendance $attendance): float
    {
        $analysis = $this->analyze($attendance);
        $distanceKm = (float) $analysis['summary']['total_distance_km'];

        $attendance->forceFill([
            'total_route_distance_km' => $distanceKm,
        ])->saveQuietly();

        return $distanceKm;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatRoutePointsForResponse(Attendance $attendance): array
    {
        $attendance->loadMissing([
            'routePoints' => fn ($query) => $query->orderBy('recorded_at')->orderBy('id'),
        ]);

        return $attendance->routePoints
            ->map(fn ($point): array => [
                'id' => $point->id,
                'local_uuid' => $point->local_uuid,
                'latitude' => (float) $point->latitude,
                'longitude' => (float) $point->longitude,
                'accuracy' => $point->accuracy !== null ? (float) $point->accuracy : null,
                'speed' => $point->speed !== null ? (float) $point->speed : null,
                'heading' => $point->heading !== null ? (float) $point->heading : null,
                'recorded_at' => $this->formatDateTime($point->recorded_at),
                'source' => $point->source,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatValidPointsForMap(array $validPoints): array
    {
        return array_map(fn (array $point): array => [
            'id' => $point['id'],
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'recorded_at' => $this->formatDateTime($point['recorded_at']),
            'accuracy' => $point['accuracy'],
            'speed' => $point['speed'],
            'heading' => $point['heading'] ?? null,
        ], $validPoints);
    }

    /**
     * Build chronological stoppage + travel journey for the left panel.
     *
     * @param  array<int, array<string, mixed>>  $validPoints
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array<string, mixed>>
     */
    public function buildJourneyEvents(Attendance $attendance, array $validPoints, array $stops): array
    {
        $events = [];
        $sequence = 0;

        $punchInAt = $attendance->punchInAt();
        $punchOutAt = $attendance->punchOutAt();

        $cursor = $punchInAt;

        if ($punchInAt !== null) {
            $events[] = [
                'id' => 'start',
                'type' => 'start',
                'sequence' => null,
                'label' => 'START',
                'location' => filled($attendance->punch_in_location)
                    ? $attendance->punch_in_location
                    : $this->coordinateLabel($attendance->punch_in_latitude, $attendance->punch_in_longitude),
                'start_time' => $this->formatDateTime($punchInAt),
                'end_time' => null,
                'time_label' => $punchInAt->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'duration_minutes' => null,
                'duration_label' => null,
                'distance_km' => null,
                'latitude' => $attendance->punch_in_latitude !== null ? (float) $attendance->punch_in_latitude : null,
                'longitude' => $attendance->punch_in_longitude !== null ? (float) $attendance->punch_in_longitude : null,
            ];
        }

        foreach ($stops as $index => $stop) {
            $stopStart = Carbon::parse($stop['start_time']);
            $stopEnd = Carbon::parse($stop['end_time']);

            if ($cursor !== null && $stopStart->greaterThan($cursor)) {
                $travel = $this->makeTravelEvent(
                    $cursor,
                    $stopStart,
                    $validPoints,
                    'travel-before-'.$index,
                );

                if ($travel !== null) {
                    $events[] = $travel;
                }
            }

            $sequence++;
            $durationMinutes = (int) ($stop['duration_minutes'] ?? $stopStart->diffInMinutes($stopEnd));

            $events[] = [
                'id' => 'stop-'.$sequence,
                'type' => 'stoppage',
                'sequence' => $sequence,
                'label' => 'Stop '.$sequence,
                'location' => $this->coordinateLabel($stop['latitude'] ?? null, $stop['longitude'] ?? null),
                'start_time' => $stop['start_time'],
                'end_time' => $stop['end_time'],
                'time_label' => $this->formatTimeRangeLabel($stopStart, $stopEnd),
                'duration_minutes' => $durationMinutes,
                'duration_label' => $this->formatDurationLabel($durationMinutes),
                'distance_km' => null,
                'latitude' => isset($stop['latitude']) ? (float) $stop['latitude'] : null,
                'longitude' => isset($stop['longitude']) ? (float) $stop['longitude'] : null,
            ];

            $cursor = $stopEnd;
        }

        if ($punchOutAt !== null) {
            if ($cursor !== null && $punchOutAt->greaterThan($cursor)) {
                $travel = $this->makeTravelEvent(
                    $cursor,
                    $punchOutAt,
                    $validPoints,
                    'travel-to-end',
                );

                if ($travel !== null) {
                    $events[] = $travel;
                }
            } elseif ($cursor === null && $punchInAt !== null && $punchOutAt->greaterThan($punchInAt)) {
                $travel = $this->makeTravelEvent(
                    $punchInAt,
                    $punchOutAt,
                    $validPoints,
                    'travel-full-day',
                );

                if ($travel !== null) {
                    $events[] = $travel;
                }
            }

            $events[] = [
                'id' => 'end',
                'type' => 'end',
                'sequence' => null,
                'label' => 'END',
                'location' => filled($attendance->punch_out_location)
                    ? $attendance->punch_out_location
                    : $this->coordinateLabel($attendance->punch_out_latitude, $attendance->punch_out_longitude),
                'start_time' => $this->formatDateTime($punchOutAt),
                'end_time' => null,
                'time_label' => $punchOutAt->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'duration_minutes' => null,
                'duration_label' => null,
                'distance_km' => null,
                'latitude' => $attendance->punch_out_latitude !== null ? (float) $attendance->punch_out_latitude : null,
                'longitude' => $attendance->punch_out_longitude !== null ? (float) $attendance->punch_out_longitude : null,
            ];
        }

        // If there are no detected stoppages but GPS exists, still show a single travel row.
        if ($stops === [] && $validPoints !== [] && ! collect($events)->contains(fn (array $e): bool => $e['type'] === 'travel')) {
            $first = $validPoints[0]['recorded_at'] ?? null;
            $last = $validPoints[array_key_last($validPoints)]['recorded_at'] ?? null;

            if ($first instanceof Carbon && $last instanceof Carbon && $last->greaterThan($first)) {
                $travel = $this->makeTravelEvent($first, $last, $validPoints, 'travel-only');

                if ($travel !== null) {
                    // Insert before END if present, otherwise append.
                    $endIndex = collect($events)->search(fn (array $e): bool => $e['type'] === 'end');

                    if ($endIndex === false) {
                        $events[] = $travel;
                    } else {
                        array_splice($events, (int) $endIndex, 0, [$travel]);
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<int, array<string, mixed>>  $validPoints
     */
    private function makeTravelEvent(Carbon $from, Carbon $to, array $validPoints, string $id): ?array
    {
        if ($to->lessThanOrEqualTo($from)) {
            return null;
        }

        $durationMinutes = max(0, $from->diffInMinutes($to));
        $distanceKm = $this->distanceBetweenTimes($validPoints, $from, $to);

        // Skip tiny gaps that are not meaningful travel.
        if ($durationMinutes < 1 && $distanceKm < 0.05) {
            return null;
        }

        return [
            'id' => $id,
            'type' => 'travel',
            'sequence' => null,
            'label' => 'Travel',
            'location' => null,
            'start_time' => $this->formatDateTime($from),
            'end_time' => $this->formatDateTime($to),
            'time_label' => $this->formatTimeRangeLabel($from, $to),
            'duration_minutes' => $durationMinutes,
            'duration_label' => $this->formatDurationLabel($durationMinutes),
            'distance_km' => $distanceKm > 0 ? round($distanceKm, 1) : null,
            'latitude' => null,
            'longitude' => null,
        ];
    }

    /**
     * Sum ordered GPS segment distances between two timestamps.
     *
     * @param  array<int, array<string, mixed>>  $validPoints
     */
    private function distanceBetweenTimes(array $validPoints, Carbon $from, Carbon $to): float
    {
        $segmentPoints = [];

        foreach ($validPoints as $point) {
            /** @var Carbon $recordedAt */
            $recordedAt = $point['recorded_at'];

            if ($recordedAt->lessThan($from) || $recordedAt->greaterThan($to)) {
                continue;
            }

            $segmentPoints[] = $point;
        }

        if (count($segmentPoints) < 2) {
            return 0.0;
        }

        $meters = 0.0;

        for ($index = 1; $index < count($segmentPoints); $index++) {
            $previous = $segmentPoints[$index - 1];
            $current = $segmentPoints[$index];

            $segmentDistance = $this->distanceCalculator->haversineDistanceMeters(
                (float) $previous['latitude'],
                (float) $previous['longitude'],
                (float) $current['latitude'],
                (float) $current['longitude'],
            );

            $hours = max(
                1 / 3600,
                $previous['recorded_at']->diffInSeconds($current['recorded_at']) / 3600,
            );
            $speedKmh = ($segmentDistance / 1000) / $hours;

            if ($speedKmh > RouteDistanceCalculator::MAX_SPEED_KMH) {
                continue;
            }

            $meters += $segmentDistance;
        }

        return $meters / 1000;
    }

    /**
     * Chronological timeline with punch in/out and route points.
     *
     * @param  array<string, mixed>  $distanceResult
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(Attendance $attendance, array $distanceResult): array
    {
        $timeline = [];
        $previousLat = null;
        $previousLng = null;

        $punchInAt = $attendance->punchInAt();
        if ($punchInAt !== null && $attendance->punch_in_latitude !== null && $attendance->punch_in_longitude !== null) {
            $timeline[] = [
                'point_type' => 'Punch In',
                'recorded_at' => $this->formatDateTime($punchInAt),
                'latitude' => (float) $attendance->punch_in_latitude,
                'longitude' => (float) $attendance->punch_in_longitude,
                'accuracy' => null,
                'distance_from_previous_m' => null,
                'distance_from_previous_km' => null,
            ];
            $previousLat = (float) $attendance->punch_in_latitude;
            $previousLng = (float) $attendance->punch_in_longitude;
        }

        foreach ($distanceResult['valid_points'] as $point) {
            $distanceM = null;
            if ($previousLat !== null && $previousLng !== null) {
                $distanceM = $this->distanceCalculator->haversineDistanceMeters(
                    $previousLat,
                    $previousLng,
                    (float) $point['latitude'],
                    (float) $point['longitude'],
                );
            }

            $timeline[] = [
                'point_type' => 'Route Point',
                'recorded_at' => $this->formatDateTime($point['recorded_at']),
                'latitude' => (float) $point['latitude'],
                'longitude' => (float) $point['longitude'],
                'accuracy' => $point['accuracy'],
                'distance_from_previous_m' => $distanceM !== null ? round($distanceM, 1) : null,
                'distance_from_previous_km' => $distanceM !== null ? round($distanceM / 1000, 3) : null,
            ];

            $previousLat = (float) $point['latitude'];
            $previousLng = (float) $point['longitude'];
        }

        $punchOutAt = $attendance->punchOutAt();
        if ($punchOutAt !== null && $attendance->punch_out_latitude !== null && $attendance->punch_out_longitude !== null) {
            $distanceM = null;
            if ($previousLat !== null && $previousLng !== null) {
                $distanceM = $this->distanceCalculator->haversineDistanceMeters(
                    $previousLat,
                    $previousLng,
                    (float) $attendance->punch_out_latitude,
                    (float) $attendance->punch_out_longitude,
                );
            }

            $timeline[] = [
                'point_type' => 'Punch Out',
                'recorded_at' => $this->formatDateTime($punchOutAt),
                'latitude' => (float) $attendance->punch_out_latitude,
                'longitude' => (float) $attendance->punch_out_longitude,
                'accuracy' => null,
                'distance_from_previous_m' => $distanceM !== null ? round($distanceM, 1) : null,
                'distance_from_previous_km' => $distanceM !== null ? round($distanceM / 1000, 3) : null,
            ];
        }

        usort($timeline, function (array $a, array $b): int {
            return strcmp((string) $a['recorded_at'], (string) $b['recorded_at']);
        });

        // Recompute distance-from-previous after chronological sort.
        $sorted = [];
        $prevLat = null;
        $prevLng = null;
        foreach ($timeline as $row) {
            $distanceM = null;
            if ($prevLat !== null && $prevLng !== null) {
                $distanceM = $this->distanceCalculator->haversineDistanceMeters(
                    $prevLat,
                    $prevLng,
                    (float) $row['latitude'],
                    (float) $row['longitude'],
                );
            }
            $row['distance_from_previous_m'] = $distanceM !== null ? round($distanceM, 1) : null;
            $row['distance_from_previous_km'] = $distanceM !== null ? round($distanceM / 1000, 3) : null;
            $sorted[] = $row;
            $prevLat = (float) $row['latitude'];
            $prevLng = (float) $row['longitude'];
        }

        return $sorted;
    }

    private function coordinateLabel(mixed $latitude, mixed $longitude): string
    {
        if ($latitude === null || $longitude === null) {
            return 'Unknown location';
        }

        return number_format((float) $latitude, 5).', '.number_format((float) $longitude, 5);
    }

    private function formatTimeRangeLabel(Carbon $from, Carbon $to): string
    {
        return $from->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A')
            .' - '
            .$to->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A');
    }

    private function formatDurationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $remainingMinutes.'m';
        }

        if ($remainingMinutes === 0) {
            return $hours.'h';
        }

        return $hours.'h '.$remainingMinutes.'m';
    }

    private function formatDateTime(Carbon $value): string
    {
        return $value->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
