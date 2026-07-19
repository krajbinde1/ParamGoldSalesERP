<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TaDaClaimRouteService
{
    public function __construct(
        private readonly EmployeeRouteAnalysisService $routeAnalysisService,
    ) {}

    /**
     * @return array{
     *     attendance_id: int,
     *     travel_km: float,
     *     valid_point_count: int,
     *     route_available: bool
     * }
     */
    public function resolveTravelKm(Employee $employee, string $claimDate): array
    {
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $claimDate)
            ->whereNotNull('punch_in_time')
            ->orderByDesc('id')
            ->first();

        if ($attendance === null) {
            throw ValidationException::withMessages([
                'claim_date' => ['No attendance record found for this date. Route distance is unavailable.'],
            ]);
        }

        $analysis = $this->routeAnalysisService->analyze($attendance);
        $travelKm = (float) $analysis['summary']['total_distance_km'];
        $validPointCount = (int) $analysis['summary']['valid_point_count'];

        if ($validPointCount < 2 || $travelKm <= 0) {
            throw ValidationException::withMessages([
                'claim_date' => [
                    'Route distance is unavailable for this date. '
                    .'Ensure route tracking captured at least 2 valid points.',
                ],
            ]);
        }

        return [
            'attendance_id' => $attendance->id,
            'travel_km' => round($travelKm, 2),
            'valid_point_count' => $validPointCount,
            'route_available' => true,
        ];
    }

    public function claimDateString(Carbon|string $claimDate): string
    {
        return Carbon::parse($claimDate)->timezone('Asia/Kolkata')->toDateString();
    }
}
