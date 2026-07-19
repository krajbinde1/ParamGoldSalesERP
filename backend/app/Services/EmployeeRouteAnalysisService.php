<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;

class EmployeeRouteAnalysisService
{
    public function __construct(
        private readonly RouteDistanceCalculator $distanceCalculator,
        private readonly RouteStopDetector $stopDetector,
    ) {}

    /**
     * @return array{
     *     summary: array{
     *         total_distance_km: float,
     *         valid_point_count: int,
     *         ignored_segment_count: int,
     *         stop_count: int
     *     },
     *     valid_points: array<int, array<string, mixed>>,
     *     stops: array<int, array<string, mixed>>
     * }
     */
    public function analyze(Attendance $attendance): array
    {
        $attendance->loadMissing([
            'routePoints' => fn ($query) => $query->orderBy('recorded_at')->orderBy('id'),
        ]);

        $distanceResult = $this->distanceCalculator->calculate($attendance->routePoints);
        $stops = $this->stopDetector->detect($distanceResult['valid_points']);

        return [
            'summary' => [
                'total_distance_km' => $distanceResult['total_distance_km'],
                'valid_point_count' => $distanceResult['valid_point_count'],
                'ignored_segment_count' => $distanceResult['ignored_segment_count'],
                'stop_count' => count($stops),
            ],
            'valid_points' => $distanceResult['valid_points'],
            'stops' => $stops,
        ];
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
        ], $validPoints);
    }

    private function formatDateTime(Carbon $value): string
    {
        return $value->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
