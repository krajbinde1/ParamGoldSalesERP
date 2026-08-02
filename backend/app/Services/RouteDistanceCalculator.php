<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RouteDistanceCalculator
{
    public const MAX_ACCURACY_METERS = 100;

    public const MAX_SPEED_KMH = 150;

    public const DUPLICATE_DISTANCE_METERS = 5;

    /**
     * @param  Collection<int, object>  $points
     * @return array{
     *     total_distance_km: float,
     *     valid_point_count: int,
     *     rejected_point_count: int,
     *     ignored_segment_count: int,
     *     valid_points: array<int, array<string, mixed>>,
     *     first_recorded_at: ?Carbon,
     *     last_recorded_at: ?Carbon,
     *     duration_minutes: ?int
     * }
     */
    public function calculate(Collection $points): array
    {
        $orderedPoints = $points
            ->sortBy([
                ['recorded_at', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $rawCount = $orderedPoints->count();
        $validPoints = $this->filterValidPoints($orderedPoints);

        $totalDistanceMeters = 0.0;
        $ignoredSegmentCount = 0;
        $segmentDistances = [];

        for ($index = 1; $index < count($validPoints); $index++) {
            $previous = $validPoints[$index - 1];
            $current = $validPoints[$index];

            $segmentDistance = $this->haversineDistanceMeters(
                (float) $previous['latitude'],
                (float) $previous['longitude'],
                (float) $current['latitude'],
                (float) $current['longitude'],
            );

            if ($this->shouldIgnoreSegment($previous, $current, $segmentDistance)) {
                $ignoredSegmentCount++;
                $segmentDistances[$index] = null;

                continue;
            }

            $totalDistanceMeters += $segmentDistance;
            $segmentDistances[$index] = $segmentDistance;
        }

        $first = $validPoints[0]['recorded_at'] ?? null;
        $last = $validPoints !== [] ? $validPoints[array_key_last($validPoints)]['recorded_at'] : null;

        return [
            'total_distance_km' => round($totalDistanceMeters / 1000, 2),
            'valid_point_count' => count($validPoints),
            'rejected_point_count' => max(0, $rawCount - count($validPoints)),
            'ignored_segment_count' => $ignoredSegmentCount,
            'valid_points' => $validPoints,
            'segment_distances_meters' => $segmentDistances,
            'first_recorded_at' => $first,
            'last_recorded_at' => $last,
            'duration_minutes' => ($first instanceof Carbon && $last instanceof Carbon)
                ? max(0, $first->diffInMinutes($last))
                : null,
        ];
    }

    /**
     * @param  Collection<int, object>  $points
     * @return array<int, array<string, mixed>>
     */
    private function filterValidPoints(Collection $points): array
    {
        $validPoints = [];

        foreach ($points as $point) {
            if (! $this->hasValidCoordinates($point)) {
                continue;
            }

            if (! $this->hasAcceptableAccuracy($point)) {
                continue;
            }

            $normalized = $this->normalizePoint($point);

            if ($validPoints !== []) {
                $lastPoint = $validPoints[array_key_last($validPoints)];

                if ($this->isDuplicateCoordinate($lastPoint, $normalized)) {
                    continue;
                }
            }

            $validPoints[] = $normalized;
        }

        return $validPoints;
    }

    private function hasValidCoordinates(object $point): bool
    {
        $latitude = (float) $point->latitude;
        $longitude = (float) $point->longitude;

        if (abs($latitude) < 0.000001 && abs($longitude) < 0.000001) {
            return false;
        }

        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    private function hasAcceptableAccuracy(object $point): bool
    {
        if ($point->accuracy === null) {
            return true;
        }

        return (float) $point->accuracy <= self::MAX_ACCURACY_METERS;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePoint(object $point): array
    {
        return [
            'id' => $point->id,
            'local_uuid' => $point->local_uuid,
            'latitude' => (float) $point->latitude,
            'longitude' => (float) $point->longitude,
            'accuracy' => $point->accuracy !== null ? (float) $point->accuracy : null,
            'speed' => $point->speed !== null ? (float) $point->speed : null,
            'heading' => isset($point->heading) && $point->heading !== null ? (float) $point->heading : null,
            'recorded_at' => Carbon::parse($point->recorded_at),
            'source' => $point->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function shouldIgnoreSegment(array $previous, array $current, float $segmentDistance): bool
    {
        // Keep small legitimate gaps; only drop impossible teleports by speed.
        if ($segmentDistance <= self::DUPLICATE_DISTANCE_METERS) {
            return false;
        }

        $elapsedSeconds = max(
            1,
            $previous['recorded_at']->diffInSeconds($current['recorded_at']),
        );

        $impliedSpeedKmh = ($segmentDistance / 1000) / ($elapsedSeconds / 3600);

        return $impliedSpeedKmh > self::MAX_SPEED_KMH;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function isDuplicateCoordinate(array $previous, array $current): bool
    {
        return $this->haversineDistanceMeters(
            (float) $previous['latitude'],
            (float) $previous['longitude'],
            (float) $current['latitude'],
            (float) $current['longitude'],
        ) <= self::DUPLICATE_DISTANCE_METERS;
    }

    public function haversineDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
