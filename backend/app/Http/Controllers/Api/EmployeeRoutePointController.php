<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\EmployeeRoutePoint;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmployeeRoutePointController extends Controller
{
    public function __construct(
        private readonly EmployeeRouteAnalysisService $routeAnalysisService,
    ) {}

    public function storeBatch(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee' => ['Employee profile is not linked to this account.'],
            ]);
        }

        $validated = $request->validate([
            'attendance_id' => ['required', 'integer', 'exists:attendances,id'],
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.local_uuid' => ['required', 'string', 'max:36'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'points.*.speed' => ['nullable', 'numeric', 'min:0'],
            'points.*.heading' => ['nullable', 'numeric', 'between:0,360'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.source' => ['nullable', 'string', 'max:50'],
        ]);

        $attendance = Attendance::query()->findOrFail($validated['attendance_id']);

        Log::info('Route point batch validation', [
            'uploaded_attendance_id' => $validated['attendance_id'],
            'backend_attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'attendance_employee_id' => $attendance->employee_id,
            'punch_in_time' => $attendance->punch_in_time,
            'punch_out_time' => $attendance->punch_out_time,
            'route_tracking_active' => $attendance->isRouteTrackingActive(),
            'point_count' => count($validated['points']),
        ]);

        if ($attendance->employee_id !== $employee->id) {
            throw ValidationException::withMessages([
                'attendance_id' => ['This attendance record does not belong to you.'],
            ]);
        }

        if (blank($attendance->punch_in_time)) {
            throw ValidationException::withMessages([
                'attendance_id' => ['Route points require an attendance record with punch-in.'],
            ]);
        }

        $sessionStart = $attendance->punchInAt()?->copy()->subMinutes(5);
        $sessionEnd = $attendance->isRouteTrackingActive()
            ? AttendanceCalendar::now()->copy()->addMinutes(15)
            : ($attendance->punchOutAt()?->copy()->addMinutes(30) ?? AttendanceCalendar::now()->copy()->addMinutes(15));

        $inserted = 0;
        $skipped = 0;
        $rejected = 0;

        DB::transaction(function () use (
            $validated,
            $attendance,
            $employee,
            $sessionStart,
            $sessionEnd,
            &$inserted,
            &$skipped,
            &$rejected,
        ): void {
            foreach ($validated['points'] as $point) {
                $latitude = (float) $point['latitude'];
                $longitude = (float) $point['longitude'];

                if (abs($latitude) < 0.000001 && abs($longitude) < 0.000001) {
                    $rejected++;

                    continue;
                }

                $recordedAt = Carbon::parse($point['recorded_at'])->timezone(AttendanceCalendar::TIMEZONE);

                if (($sessionStart !== null && $recordedAt->lt($sessionStart))
                    || ($sessionEnd !== null && $recordedAt->gt($sessionEnd))) {
                    $rejected++;

                    continue;
                }

                $created = EmployeeRoutePoint::query()->firstOrCreate(
                    ['local_uuid' => $point['local_uuid']],
                    [
                        'attendance_id' => $attendance->id,
                        'employee_id' => $employee->id,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'accuracy' => $point['accuracy'] ?? null,
                        'speed' => $point['speed'] ?? null,
                        'heading' => $point['heading'] ?? null,
                        'recorded_at' => $recordedAt,
                        'source' => filled($point['source'] ?? null) ? $point['source'] : null,
                    ],
                );

                if ($created->wasRecentlyCreated) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
        });

        $distanceKm = $this->routeAnalysisService->recalculateAndPersistDistance($attendance->fresh());

        return response()->json([
            'message' => 'Route points processed successfully.',
            'inserted' => $inserted,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'total_route_distance_km' => $distanceKm,
        ], 201);
    }
}
