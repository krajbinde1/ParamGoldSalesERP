<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEmployeeRouteController extends Controller
{
    public function __construct(
        private readonly EmployeeRouteAnalysisService $routeAnalysisService,
    ) {}

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        if ($request->user()->role === 'employee') {
            abort(403, 'Admin access required.');
        }

        $attendance->load([
            'employee:id,employee_code,full_name,mobile,designation,department',
        ]);

        $analysis = $this->routeAnalysisService->analyze($attendance);

        return response()->json([
            'employee' => [
                'id' => $attendance->employee?->id,
                'employee_code' => $attendance->employee?->employee_code,
                'full_name' => $attendance->employee?->full_name,
                'mobile' => $attendance->employee?->mobile,
                'designation' => $attendance->employee?->designation,
                'department' => $attendance->employee?->department,
            ],
            'attendance' => [
                'id' => $attendance->id,
                'attendance_date' => $attendance->attendance_date->toDateString(),
                'attendance_status' => $attendance->attendance_status,
                'approval_status' => $attendance->approval_status,
                'working_hours' => $attendance->working_hours,
                'total_working_minutes' => $attendance->total_working_minutes,
                'total_route_distance_km' => $attendance->total_route_distance_km !== null
                    ? (float) $attendance->total_route_distance_km
                    : $analysis['summary']['total_distance_km'],
                'punch_in' => [
                    'time' => $this->formatIstDateTime($attendance->punchInAt()),
                    'location' => $attendance->punch_in_location,
                    'latitude' => $attendance->punch_in_latitude !== null ? (float) $attendance->punch_in_latitude : null,
                    'longitude' => $attendance->punch_in_longitude !== null ? (float) $attendance->punch_in_longitude : null,
                ],
                'punch_out' => [
                    'time' => $this->formatIstDateTime($attendance->punchOutAt()),
                    'location' => $attendance->punch_out_location,
                    'latitude' => $attendance->punch_out_latitude !== null ? (float) $attendance->punch_out_latitude : null,
                    'longitude' => $attendance->punch_out_longitude !== null ? (float) $attendance->punch_out_longitude : null,
                ],
            ],
            'summary' => $analysis['summary'],
            'diagnostics' => $analysis['diagnostics'],
            'timeline' => $analysis['timeline'],
            'journey_events' => $analysis['journey_events'],
            'route_points' => $this->routeAnalysisService->formatRoutePointsForResponse($attendance),
            'stops' => $analysis['stops'],
        ]);
    }

    private function formatIstDateTime(?\Illuminate\Support\Carbon $value): ?string
    {
        return $value?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
