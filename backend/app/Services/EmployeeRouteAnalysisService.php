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
            ],
            'diagnostics' => $diagnostics,
            'valid_points' => $distanceResult['valid_points'],
            'stops' => $stops,
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

    private function formatDateTime(Carbon $value): string
    {
        return $value->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
