<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeRoutePoint;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;
use App\Support\AttendanceCalendar;
use App\Support\LiveTracking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class LiveTrackingService
{
    public function __construct(
        private readonly RouteDistanceCalculator $distanceCalculator,
        private readonly RouteStopDetector $stopDetector,
        private readonly ManagerOrderAccessService $managerAccess,
    ) {}

    public function canView(User $user): bool
    {
        if ($user->hasOrdersOnlyFilamentAccess()) {
            return false;
        }

        return $user->usesAdminDirectorDashboard() || $user->isManagerUser();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $this->assertCanView($user);

        $now = AttendanceCalendar::now();
        $attendances = $this->openAttendances($user, $now);
        $attendanceIds = $attendances->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $latestPoints = $this->latestPoints($attendanceIds);
        $recentPoints = $this->recentPoints($attendanceIds, $now);

        $employees = [];
        $counts = [
            LiveTracking::STATUS_MOVING => 0,
            LiveTracking::STATUS_STOPPED => 0,
            LiveTracking::STATUS_DELAYED => 0,
            LiveTracking::STATUS_OFFLINE => 0,
            LiveTracking::STATUS_NO_GPS => 0,
        ];
        $lastUpdated = null;

        foreach ($attendances as $attendance) {
            $latest = $latestPoints->get($attendance->id);
            $window = $recentPoints->get($attendance->id, collect());
            $status = $this->statusFor($latest, $window, $now);
            $counts[$status]++;

            $recordedAt = $latest?->recorded_at !== null
                ? Carbon::parse($latest->recorded_at)->timezone(AttendanceCalendar::TIMEZONE)
                : null;
            if ($recordedAt !== null && ($lastUpdated === null || $recordedAt->greaterThan($lastUpdated))) {
                $lastUpdated = $recordedAt;
            }

            $punchInAt = $attendance->punchInAt();
            $employee = $attendance->employee;

            $employees[] = [
                'employee_id' => (int) $attendance->employee_id,
                'employee_name' => $employee?->full_name ?? 'Employee',
                'initials' => $this->initials($employee?->full_name),
                'profile_photo_url' => $this->profilePhotoUrl($employee),
                'designation' => $employee?->designation,
                'mobile' => $employee?->mobile,
                'attendance_id' => (int) $attendance->id,
                'route_session_id' => (int) $attendance->id,
                'latitude' => $latest !== null ? (float) $latest->latitude : null,
                'longitude' => $latest !== null ? (float) $latest->longitude : null,
                'accuracy' => $latest?->accuracy !== null ? (float) $latest->accuracy : null,
                'recorded_at' => $recordedAt?->toIso8601String(),
                'seconds_ago' => $recordedAt !== null ? max(0, $recordedAt->diffInSeconds($now)) : null,
                'status' => $status,
                'gps_status' => $status === LiveTracking::STATUS_NO_GPS ? 'no_gps' : $status,
                'punched_in' => true,
                'route_status' => 'open',
                'today_distance_km' => round((float) ($attendance->total_route_distance_km ?? 0), 2),
                'punch_in_at' => $punchInAt?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String(),
                'punch_in_label' => $punchInAt?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'working_minutes' => $punchInAt !== null ? max(0, $punchInAt->diffInMinutes($now)) : 0,
                'punch_in_location' => $attendance->punch_in_location,
                'punch_in_latitude' => $attendance->punch_in_latitude !== null ? (float) $attendance->punch_in_latitude : null,
                'punch_in_longitude' => $attendance->punch_in_longitude !== null ? (float) $attendance->punch_in_longitude : null,
                'battery_percent' => null,
            ];
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'poll_seconds' => LiveTracking::POLL_SECONDS,
            'summary' => [
                'active_employees' => $attendances->count(),
                'punch_in_today' => $this->punchInTodayCount($user, $now),
                'moving' => $counts[LiveTracking::STATUS_MOVING],
                'stopped' => $counts[LiveTracking::STATUS_STOPPED],
                'delayed' => $counts[LiveTracking::STATUS_DELAYED],
                'offline' => $counts[LiveTracking::STATUS_OFFLINE],
                'no_gps' => $counts[LiveTracking::STATUS_NO_GPS],
                'last_updated_at' => $lastUpdated?->toIso8601String(),
            ],
            'employees' => $employees,
        ];
    }

    /**
     * Today's route for one punched-in employee.
     * after_point_id returns only newer points.
     *
     * @return array<string, mixed>
     */
    public function routeForEmployee(User $user, int $employeeId, ?int $afterPointId = null): array
    {
        $this->assertCanView($user);
        $this->assertEmployeeVisible($user, $employeeId);

        $now = AttendanceCalendar::now();
        $attendance = $this->openAttendances($user, $now)
            ->firstWhere('employee_id', $employeeId);

        if ($attendance === null) {
            abort(404, 'This employee is not on a live route today.');
        }

        $pointsQuery = EmployeeRoutePoint::query()
            ->where('attendance_id', $attendance->id)
            ->orderBy('id');

        if ($afterPointId !== null) {
            $pointsQuery->where('id', '>', $afterPointId);
        }

        $points = $pointsQuery->get();
        $mapped = $points->map(fn (EmployeeRoutePoint $point): array => [
            'id' => (int) $point->id,
            'latitude' => (float) $point->latitude,
            'longitude' => (float) $point->longitude,
            'accuracy' => $point->accuracy !== null ? (float) $point->accuracy : null,
            'recorded_at' => Carbon::parse($point->recorded_at)->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String(),
        ])->values()->all();

        $stops = [];
        if ($afterPointId === null) {
            $analysisPoints = $this->distanceCalculator->calculate(
                EmployeeRoutePoint::query()->where('attendance_id', $attendance->id)->get(),
            );
            $stops = array_map(function (array $stop): array {
                unset($stop['_start'], $stop['_end']);

                return $stop;
            }, $this->stopDetector->detect($analysisPoints['valid_points']));
        }

        $punchInAt = $attendance->punchInAt();

        return [
            'employee_id' => $employeeId,
            'employee_name' => $attendance->employee?->full_name,
            'attendance_id' => (int) $attendance->id,
            'punch_in' => [
                'time' => $punchInAt?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String(),
                'label' => $punchInAt?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                'location' => $attendance->punch_in_location,
                'latitude' => $attendance->punch_in_latitude !== null ? (float) $attendance->punch_in_latitude : null,
                'longitude' => $attendance->punch_in_longitude !== null ? (float) $attendance->punch_in_longitude : null,
            ],
            'working_minutes' => $punchInAt !== null ? max(0, $punchInAt->diffInMinutes($now)) : 0,
            'today_distance_km' => round((float) ($attendance->total_route_distance_km ?? 0), 2),
            'current_time' => $now->toIso8601String(),
            'points' => $mapped,
            'stops' => $stops,
            'latest_point_id' => $points->last()?->id !== null
                ? (int) $points->last()->id
                : $afterPointId,
        ];
    }

    /**
     * @param  Collection<int, EmployeeRoutePoint>  $window
     */
    public function statusFor(?EmployeeRoutePoint $latest, Collection $window, Carbon $now): string
    {
        if ($latest === null || $latest->recorded_at === null) {
            return LiveTracking::STATUS_NO_GPS;
        }

        $recordedAt = Carbon::parse($latest->recorded_at)->timezone(AttendanceCalendar::TIMEZONE);
        $ageMinutes = $recordedAt->diffInMinutes($now);

        if ($ageMinutes > LiveTracking::OFFLINE_AFTER_MINUTES) {
            return LiveTracking::STATUS_OFFLINE;
        }

        if ($ageMinutes > LiveTracking::DELAYED_AFTER_MINUTES) {
            return LiveTracking::STATUS_DELAYED;
        }

        return $this->hasMeaningfulMovement($window, $now)
            ? LiveTracking::STATUS_MOVING
            : LiveTracking::STATUS_STOPPED;
    }

    private function assertCanView(User $user): void
    {
        if (! $this->canView($user)) {
            abort(403, 'You cannot view live employee locations.');
        }
    }

    private function assertEmployeeVisible(User $user, int $employeeId): void
    {
        if ($user->usesAdminDirectorDashboard()) {
            return;
        }

        if (! in_array($employeeId, $this->managerAccess->directReportEmployeeIds($user), true)) {
            abort(403, 'You can only view employees who report to you.');
        }
    }

    /**
     * @return Collection<int, Attendance>
     */
    private function openAttendances(User $user, Carbon $now): Collection
    {
        return $this->scopedAttendances($user)
            ->whereDate('attendance_date', $now->timezone(AttendanceCalendar::TIMEZONE)->toDateString())
            ->whereNotNull('punch_in_time')
            ->whereNull('punch_out_time')
            ->with(['employee:id,full_name,mobile,designation,employee_code,profile_photo_path,updated_at'])
            ->orderBy('employee_id')
            ->get();
    }

    private function punchInTodayCount(User $user, Carbon $now): int
    {
        return $this->scopedAttendances($user)
            ->whereDate('attendance_date', $now->timezone(AttendanceCalendar::TIMEZONE)->toDateString())
            ->whereNotNull('punch_in_time')
            ->count();
    }

    /**
     * @return Builder<Attendance>
     */
    private function scopedAttendances(User $user): Builder
    {
        $query = Attendance::query();

        if ($user->usesAdminDirectorDashboard()) {
            return $query;
        }

        $employeeIds = $this->managerAccess->directReportEmployeeIds($user);
        if ($employeeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employee_id', $employeeIds);
    }

    /**
     * @param  list<int>  $attendanceIds
     * @return Collection<int, EmployeeRoutePoint>
     */
    private function latestPoints(array $attendanceIds): Collection
    {
        if ($attendanceIds === []) {
            return collect();
        }

        $latestIds = EmployeeRoutePoint::query()
            ->whereIn('attendance_id', $attendanceIds)
            ->select('attendance_id')
            ->selectRaw('MAX(id) as latest_id')
            ->groupBy('attendance_id')
            ->pluck('latest_id');

        return EmployeeRoutePoint::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('attendance_id');
    }

    /**
     * @param  list<int>  $attendanceIds
     * @return Collection<int, Collection<int, EmployeeRoutePoint>>
     */
    private function recentPoints(array $attendanceIds, Carbon $now): Collection
    {
        if ($attendanceIds === []) {
            return collect();
        }

        return EmployeeRoutePoint::query()
            ->whereIn('attendance_id', $attendanceIds)
            ->where('recorded_at', '>=', $now->copy()->subMinutes(LiveTracking::MOVING_WINDOW_MINUTES))
            ->orderBy('id')
            ->get()
            ->groupBy('attendance_id');
    }

    /**
     * @param  Collection<int, EmployeeRoutePoint>  $window
     */
    private function hasMeaningfulMovement(Collection $window, Carbon $now): bool
    {
        $start = $now->copy()->subMinutes(LiveTracking::MOVING_WINDOW_MINUTES);
        $points = $window
            ->filter(function (EmployeeRoutePoint $point) use ($start): bool {
                if ($point->accuracy !== null && (float) $point->accuracy > RouteDistanceCalculator::MAX_ACCURACY_METERS) {
                    return false;
                }

                return Carbon::parse($point->recorded_at)->greaterThanOrEqualTo($start);
            })
            ->sortBy(fn (EmployeeRoutePoint $point): string => Carbon::parse($point->recorded_at)->format('Y-m-d H:i:s').'-'.$point->id)
            ->values();

        for ($index = 1; $index < $points->count(); $index++) {
            $previous = $points[$index - 1];
            $current = $points[$index];
            $meters = $this->distanceCalculator->haversineDistanceMeters(
                (float) $previous->latitude,
                (float) $previous->longitude,
                (float) $current->latitude,
                (float) $current->longitude,
            );

            if ($meters < LiveTracking::MOVING_METERS) {
                continue;
            }

            $elapsedSeconds = max(1, Carbon::parse($previous->recorded_at)->diffInSeconds(Carbon::parse($current->recorded_at)));
            $impliedSpeedKmh = ($meters / 1000) / ($elapsedSeconds / 3600);
            if ($impliedSpeedKmh <= RouteDistanceCalculator::MAX_SPEED_KMH) {
                return true;
            }
        }

        return false;
    }

    private function profilePhotoUrl(?Employee $employee): ?string
    {
        if ($employee === null || ! filled($employee->profile_photo_path)) {
            return null;
        }

        $url = Storage::disk('public')->url($employee->profile_photo_path);
        $version = $employee->updated_at?->getTimestamp() ?? time();

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$version;
    }

    private function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'E';
        }

        $first = mb_substr($parts[0], 0, 1);
        $second = count($parts) > 1
            ? mb_substr($parts[array_key_last($parts)], 0, 1)
            : mb_substr($parts[0], 1, 1);

        return mb_strtoupper($first.$second);
    }
}
