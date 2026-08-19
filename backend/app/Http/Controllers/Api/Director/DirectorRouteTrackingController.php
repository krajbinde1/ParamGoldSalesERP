<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director view-only route tracking for Sales Team (manager + employee login roles).
 */
class DirectorRouteTrackingController extends Controller
{
    /** @var list<string> */
    private const SALES_TEAM_ROLES = [
        UserRole::Manager->value,
        UserRole::Employee->value,
    ];

    public function __construct(
        private readonly EmployeeRouteAnalysisService $routeAnalysisService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();

        $employees = Employee::query()
            ->with(['user:id,employee_id,role'])
            ->where('status', true)
            ->whereHas(
                'user',
                fn ($q) => $q->whereIn('role', self::SALES_TEAM_ROLES),
            )
            ->when(filled($validated['search'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['search'].'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term);
                });
            })
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code']);

        $attendances = Attendance::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use ($attendances, $date): array {
            $role = (string) ($employee->user?->role ?? UserRole::Employee->value);
            $roleLabel = UserRole::tryFromMixed($role)->label();
            $attendance = $attendances->get($employee->id);

            if ($attendance === null) {
                return [
                    'id' => null,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name,
                    'employee_code' => $employee->employee_code,
                    'role' => $role,
                    'role_label' => $roleLabel,
                    'attendance_date' => $date,
                    'attendance_status' => 'Not Punched In',
                    'display_status' => 'Not Punched In',
                    'punch_in_time' => null,
                    'punch_out_time' => null,
                    'working_hours' => null,
                    'total_working_minutes' => null,
                    'total_route_distance_km' => null,
                    'has_attendance' => false,
                    'has_route' => false,
                ];
            }

            return $this->listItem($attendance, $employee, $role, $roleLabel);
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $date,
                'total_employees' => $rows->count(),
            ],
        ]);
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->ensureSalesTeamAttendance($attendance);

        $attendance->load('employee.user:id,employee_id,role');
        $analysis = $this->routeAnalysisService->analyze($attendance);
        $routePoints = $this->routeAnalysisService->formatRoutePointsForResponse($attendance);
        $hasRoute = count($routePoints) > 0
            || ($attendance->punch_in_latitude !== null && $attendance->punch_in_longitude !== null);

        $role = (string) ($attendance->employee?->user?->role ?? UserRole::Employee->value);

        return response()->json([
            'data' => [
                'employee' => [
                    'id' => $attendance->employee?->id,
                    'employee_code' => $attendance->employee?->employee_code,
                    'full_name' => $attendance->employee?->full_name,
                    'mobile' => $attendance->employee?->mobile,
                    'role' => $role,
                    'role_label' => UserRole::tryFromMixed($role)->label(),
                ],
                'attendance' => [
                    'id' => $attendance->id,
                    'attendance_date' => $attendance->attendance_date->toDateString(),
                    'attendance_status' => $attendance->attendance_status,
                    'display_status' => $this->displayStatus($attendance),
                    'approval_status' => $attendance->approval_status,
                    'working_hours' => $attendance->working_hours,
                    'total_working_minutes' => $attendance->total_working_minutes,
                    'total_route_distance_km' => $attendance->total_route_distance_km !== null
                        ? (float) $attendance->total_route_distance_km
                        : ($analysis['summary']['total_distance_km'] ?? null),
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
                'has_route' => $hasRoute,
                'route_points' => $routePoints,
                'stops' => $analysis['stops'] ?? [],
                'timeline' => $analysis['timeline'] ?? [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(
        Attendance $attendance,
        Employee $employee,
        string $role,
        string $roleLabel,
    ): array {
        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'employee_name' => $employee->full_name,
            'employee_code' => $employee->employee_code,
            'role' => $role,
            'role_label' => $roleLabel,
            'attendance_date' => $attendance->attendance_date->toDateString(),
            'attendance_status' => $attendance->attendance_status,
            'display_status' => $this->displayStatus($attendance),
            'punch_in_time' => $this->formatIstDateTime($attendance->punchInAt()),
            'punch_out_time' => $this->formatIstDateTime($attendance->punchOutAt()),
            'working_hours' => $attendance->working_hours,
            'total_working_minutes' => $attendance->total_working_minutes,
            'total_route_distance_km' => $attendance->total_route_distance_km !== null
                ? (float) $attendance->total_route_distance_km
                : null,
            'has_attendance' => true,
            'has_route' => $attendance->total_route_distance_km !== null
                || ($attendance->punch_in_latitude !== null && $attendance->punch_in_longitude !== null),
        ];
    }

    private function ensureSalesTeamAttendance(Attendance $attendance): void
    {
        $attendance->loadMissing('employee.user:id,employee_id,role');
        $role = (string) ($attendance->employee?->user?->role ?? '');

        if (! in_array($role, self::SALES_TEAM_ROLES, true)) {
            abort(403, 'Route tracking is limited to Manager and Employee login roles.');
        }
    }

    private function displayStatus(Attendance $attendance): string
    {
        if ($attendance->punchInAt() === null) {
            return 'Not Punched In';
        }

        if ($attendance->punchOutAt() === null) {
            return 'Active';
        }

        return 'Completed';
    }

    private function formatIstDateTime(?\Illuminate\Support\Carbon $value): ?string
    {
        return $value?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }
}
