<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\EmployeeRouteAnalysisService;
use App\Services\Orders\ManagerOrderAccessService;
use App\Support\AttendanceCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerTeamAttendanceController extends Controller
{
    public function __construct(
        private readonly ManagerOrderAccessService $access,
        private readonly EmployeeRouteAnalysisService $routeAnalysisService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $reportIds = $this->access->directReportEmployeeIds($request->user());
        $date = $validated['date'] ?? AttendanceCalendar::today()->toDateString();

        if ($reportIds === []) {
            return response()->json([
                'data' => [],
                'meta' => $this->emptyMeta($date),
            ]);
        }

        $employees = Employee::query()
            ->whereIn('id', $reportIds)
            ->where('status', true)
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
            $attendance = $attendances->get($employee->id);

            if ($attendance === null) {
                return [
                    'id' => null,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->full_name,
                    'employee_code' => $employee->employee_code,
                    'attendance_date' => $date,
                    'attendance_status' => 'Not Punched In',
                    'display_status' => 'Not Punched In',
                    'punch_in_time' => null,
                    'punch_out_time' => null,
                    'working_hours' => null,
                    'total_working_minutes' => null,
                    'total_route_distance_km' => null,
                    'has_attendance' => false,
                ];
            }

            return $this->listItem($attendance, $employee);
        })->values();

        $punchedIn = $rows->filter(fn (array $row): bool => $row['has_attendance'] === true)->count();
        $punchedOut = $rows->filter(
            fn (array $row): bool => $row['has_attendance'] === true && filled($row['punch_out_time']),
        )->count();
        $notPunchedIn = $rows->count() - $punchedIn;

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $date,
                'total_employees' => $rows->count(),
                'punched_in' => $punchedIn,
                'punched_out' => $punchedOut,
                'not_punched_in' => $notPunchedIn,
                'present_count' => $punchedIn,
            ],
        ]);
    }

    public function employeeHistory(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureTeamEmployee($request, $employee);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $month = $validated['month'] ?? AttendanceCalendar::today()->format('Y-m');
        $monthStart = AttendanceCalendar::today()->copy()->startOfMonth();
        try {
            $monthStart = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month, AttendanceCalendar::TIMEZONE)
                ->startOfMonth();
        } catch (\Throwable) {
            // keep default month
        }
        $monthEnd = $monthStart->copy()->endOfMonth();

        $dateFrom = $validated['date_from'] ?? $monthStart->toDateString();
        $dateTo = $validated['date_to'] ?? $monthEnd->toDateString();

        $attendances = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $dateFrom)
            ->whereDate('attendance_date', '<=', $dateTo)
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
            ],
            'meta' => [
                'month' => $monthStart->format('Y-m'),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'data' => $attendances->map(fn (Attendance $attendance): array => $this->listItem($attendance, $employee))->values(),
        ]);
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        $this->ensureTeamAttendance($request, $attendance);

        $attendance->load('employee:id,full_name,employee_code,mobile,designation,department');
        $analysis = $this->routeAnalysisService->analyze($attendance);
        $routePoints = $this->routeAnalysisService->formatRoutePointsForResponse($attendance);
        $hasRoute = count($routePoints) > 0
            || ($attendance->punch_in_latitude !== null && $attendance->punch_in_longitude !== null);

        return response()->json([
            'data' => [
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
                        'photo_url' => $this->photoUrl($attendance->punch_in_photo),
                    ],
                    'punch_out' => [
                        'time' => $this->formatIstDateTime($attendance->punchOutAt()),
                        'location' => $attendance->punch_out_location,
                        'latitude' => $attendance->punch_out_latitude !== null ? (float) $attendance->punch_out_latitude : null,
                        'longitude' => $attendance->punch_out_longitude !== null ? (float) $attendance->punch_out_longitude : null,
                        'photo_url' => $this->photoUrl($attendance->punch_out_photo),
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
    private function listItem(Attendance $attendance, ?Employee $employee = null): array
    {
        $employee ??= $attendance->employee;

        return [
            'id' => $attendance->id,
            'employee_id' => $attendance->employee_id,
            'employee_name' => $employee?->full_name ?? $attendance->employee?->full_name,
            'employee_code' => $employee?->employee_code ?? $attendance->employee?->employee_code,
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

    private function displayStatus(Attendance $attendance): string
    {
        if ($attendance->punchInAt() === null) {
            return 'Not Punched In';
        }

        if ($attendance->punchOutAt() === null) {
            return 'Working';
        }

        return 'Completed';
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyMeta(string $date): array
    {
        return [
            'date' => $date,
            'total_employees' => 0,
            'punched_in' => 0,
            'punched_out' => 0,
            'not_punched_in' => 0,
            'present_count' => 0,
        ];
    }

    private function ensureTeamAttendance(Request $request, Attendance $attendance): void
    {
        $reportIds = $this->access->directReportEmployeeIds($request->user());

        if (! in_array((int) $attendance->employee_id, $reportIds, true)) {
            abort(403, 'You can only view attendance of employees reporting to you.');
        }
    }

    private function ensureTeamEmployee(Request $request, Employee $employee): void
    {
        $reportIds = $this->access->directReportEmployeeIds($request->user());

        if (! in_array((int) $employee->id, $reportIds, true)) {
            abort(403, 'You can only view attendance of employees reporting to you.');
        }
    }

    private function formatIstDateTime(?\Illuminate\Support\Carbon $value): ?string
    {
        return $value?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String();
    }

    private function photoUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return url('storage/'.ltrim(str_replace('\\', '/', $path), '/'));
    }
}
