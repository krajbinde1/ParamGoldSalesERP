<?php

namespace App\Services;

use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;

class RouteStopDetector
{
    public const STOP_RADIUS_METERS = 75;

    public const STOP_MIN_DURATION_MINUTES = 10;

    public const STOP_MERGE_GAP_MINUTES = 5;

    public function __construct(
        private readonly RouteDistanceCalculator $distanceCalculator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $validPoints
     * @return array<int, array<string, mixed>>
     */
    public function detect(array $validPoints): array
    {
        if ($validPoints === []) {
            return [];
        }

        $rawStops = [];
        $clusterPoints = [];
        $clusterStart = null;
        $clusterEnd = null;

        foreach ($validPoints as $point) {
            if ($clusterPoints === []) {
                $clusterPoints = [$point];
                $clusterStart = $point['recorded_at'];
                $clusterEnd = $point['recorded_at'];

                continue;
            }

            $center = $this->calculateCenter($clusterPoints);

            $distanceFromCenter = $this->distanceCalculator->haversineDistanceMeters(
                $center['latitude'],
                $center['longitude'],
                (float) $point['latitude'],
                (float) $point['longitude'],
            );

            if ($distanceFromCenter <= self::STOP_RADIUS_METERS) {
                $clusterPoints[] = $point;
                $clusterEnd = $point['recorded_at'];

                continue;
            }

            $stop = $this->buildStop($clusterPoints, $clusterStart, $clusterEnd);

            if ($stop !== null) {
                $rawStops[] = $stop;
            }

            $clusterPoints = [$point];
            $clusterStart = $point['recorded_at'];
            $clusterEnd = $point['recorded_at'];
        }

        $finalStop = $this->buildStop($clusterPoints, $clusterStart, $clusterEnd);

        if ($finalStop !== null) {
            $rawStops[] = $finalStop;
        }

        return $this->mergeStops($rawStops);
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array{latitude: float, longitude: float}
     */
    private function calculateCenter(array $points): array
    {
        $latitudeTotal = 0.0;
        $longitudeTotal = 0.0;

        foreach ($points as $point) {
            $latitudeTotal += (float) $point['latitude'];
            $longitudeTotal += (float) $point['longitude'];
        }

        $count = count($points);

        return [
            'latitude' => $latitudeTotal / $count,
            'longitude' => $longitudeTotal / $count,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    private function buildStop(array $points, ?Carbon $start, ?Carbon $end): ?array
    {
        if ($start === null || $end === null || $points === []) {
            return null;
        }

        $durationMinutes = $start->diffInMinutes($end);

        if ($durationMinutes < self::STOP_MIN_DURATION_MINUTES) {
            return null;
        }

        $center = $this->calculateCenter($points);

        return [
            'start_time' => $this->formatDateTime($start),
            'end_time' => $this->formatDateTime($end),
            'duration_minutes' => $durationMinutes,
            'latitude' => round($center['latitude'], 7),
            'longitude' => round($center['longitude'], 7),
            '_start' => $start,
            '_end' => $end,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array<string, mixed>>
     */
    private function mergeStops(array $stops): array
    {
        if ($stops === []) {
            return [];
        }

        usort($stops, fn (array $left, array $right): int => $left['_start']->getTimestamp() <=> $right['_start']->getTimestamp());

        $merged = [$stops[0]];

        for ($index = 1; $index < count($stops); $index++) {
            $current = $stops[$index];
            $lastIndex = count($merged) - 1;
            $previous = $merged[$lastIndex];

            $gapMinutes = $previous['_end']->diffInMinutes($current['_start']);
            $centerDistance = $this->distanceCalculator->haversineDistanceMeters(
                (float) $previous['latitude'],
                (float) $previous['longitude'],
                (float) $current['latitude'],
                (float) $current['longitude'],
            );

            $overlaps = $current['_start']->lessThanOrEqualTo($previous['_end']);
            $isAdjacent = $gapMinutes <= self::STOP_MERGE_GAP_MINUTES;

            if (($overlaps || $isAdjacent) && $centerDistance <= self::STOP_RADIUS_METERS) {
                $merged[$lastIndex] = [
                    'start_time' => $previous['start_time'],
                    'end_time' => $current['end_time'],
                    'duration_minutes' => $previous['_start']->diffInMinutes($current['_end']),
                    'latitude' => round(((float) $previous['latitude'] + (float) $current['latitude']) / 2, 7),
                    'longitude' => round(((float) $previous['longitude'] + (float) $current['longitude']) / 2, 7),
                    '_start' => $previous['_start'],
                    '_end' => $current['_end'],
                ];

                continue;
            }

            $merged[] = $current;
        }

        return array_map(function (array $stop): array {
            unset($stop['_start'], $stop['_end']);

            return $stop;
        }, $merged);
    }

    private function formatDateTime(Carbon $value): string
    {
        return $value->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
