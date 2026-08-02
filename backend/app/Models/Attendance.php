<?php

namespace App\Models;

use App\Support\AttendanceCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Attendance extends Model
{
    use SoftDeletes;

    public const ATTENDANCE_STATUS_LABELS = [
        'Present' => 'Present', 'Absent' => 'Absent', 'Half Day' => 'Half Day', 'Leave' => 'Leave', 'Weekly Off' => 'Weekly Off',
    ];

    public const APPROVAL_STATUS_LABELS = [
        'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected',
    ];

    private const PRESENT_STATUSES = ['Present', 'Half Day'];

    public const ADMIN_MIN_PRESENT_MINUTES = 480;

    protected static function booted(): void
    {
        static::saving(function (Attendance $attendance): void {
            if (filled($attendance->punch_in_time) && filled($attendance->punch_out_time)) {
                $punchIn = Carbon::parse(
                    $attendance->attendance_date->toDateString().' '.$attendance->punch_in_time,
                    AttendanceCalendar::TIMEZONE,
                );
                $punchOut = Carbon::parse(
                    $attendance->attendance_date->toDateString().' '.$attendance->punch_out_time,
                    AttendanceCalendar::TIMEZONE,
                );

                if ($punchOut->lessThan($punchIn)) {
                    $punchOut->addDay();
                }

                $minutes = $punchIn->diffInMinutes($punchOut);
                $attendance->working_hours = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
                $attendance->total_working_minutes = $minutes;
            } else {
                $attendance->working_hours = null;
            }
        });
    }

    protected $fillable = [
        'employee_id', 'attendance_date', 'punch_in_time', 'punch_out_time', 'attendance_status', 'working_hours',
        'punch_in_location', 'punch_out_location', 'punch_in_latitude', 'punch_in_longitude', 'punch_out_latitude', 'punch_out_longitude',
        'remarks', 'approved_by', 'approval_status', 'punch_in_photo', 'punch_out_photo', 'rejection_reason', 'approved_at', 'rejected_by', 'rejected_at', 'total_working_minutes', 'total_route_distance_km',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'punch_in_latitude' => 'decimal:7', 'punch_in_longitude' => 'decimal:7',
            'punch_out_latitude' => 'decimal:7', 'punch_out_longitude' => 'decimal:7',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'total_working_minutes' => 'integer',
            'total_route_distance_km' => 'decimal:2',
        ];
    }

    public static function businessNow(): Carbon
    {
        return AttendanceCalendar::now();
    }

    public static function businessToday(): Carbon
    {
        return AttendanceCalendar::today();
    }

    public static function monthlySummary(int $employeeId, int $month, int $year): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $workingDays = AttendanceCalendar::workingDaysInPeriod($periodStart, $periodEnd);

        $records = self::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get();

        $presentDays = $records
            ->filter(fn (self $attendance): bool => in_array($attendance->attendance_status, self::PRESENT_STATUSES, true))
            ->count();

        $punchInDays = $records->filter(fn (self $attendance): bool => filled($attendance->punch_in_time))->count();
        $punchOutDays = $records->filter(fn (self $attendance): bool => filled($attendance->punch_out_time))->count();
        $absentDays = max(0, $workingDays - $presentDays);

        return [
            'month' => $month,
            'year' => $year,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'punch_in_days' => $punchInDays,
            'punch_out_days' => $punchOutDays,
        ];
    }

    public static function adminEmployeeMonthlySummary(int $employeeId, int $month, int $year): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $workingDays = AttendanceCalendar::workingDaysInPeriod($periodStart, $periodEnd);

        $records = self::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get();

        $presentDays = $records
            ->groupBy(fn (self $attendance): string => $attendance->attendance_date->toDateString())
            ->filter(function ($dateRecords): bool {
                $longestMinutes = $dateRecords
                    ->map(fn (self $attendance): ?int => $attendance->workingDurationMinutes())
                    ->filter()
                    ->max();

                return $longestMinutes !== null
                    && $longestMinutes >= self::ADMIN_MIN_PRESENT_MINUTES;
            })
            ->count();

        return [
            'employee_id' => $employeeId,
            'month' => $month,
            'year' => $year,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => max(0, $workingDays - $presentDays),
        ];
    }

    public function workingDurationMinutes(): ?int
    {
        if (blank($this->punch_in_time) || blank($this->punch_out_time)) {
            return null;
        }

        if ($this->total_working_minutes !== null && $this->total_working_minutes > 0) {
            return $this->total_working_minutes;
        }

        $punchIn = $this->punchInAt();
        $punchOut = $this->punchOutAt();

        if ($punchIn === null || $punchOut === null) {
            return null;
        }

        if ($punchOut->lessThan($punchIn)) {
            $punchOut = $punchOut->copy()->addDay();
        }

        return $punchIn->diffInMinutes($punchOut);
    }

    public function isRouteTrackingActive(): bool
    {
        return filled($this->punch_in_time) && blank($this->punch_out_time);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function routePoints(): HasMany
    {
        return $this->hasMany(EmployeeRoutePoint::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    public function punchInAt(): ?Carbon
    {
        if (blank($this->punch_in_time)) {
            return null;
        }

        return Carbon::parse(
            $this->attendance_date->toDateString().' '.$this->punch_in_time,
            AttendanceCalendar::TIMEZONE,
        );
    }

    public function punchOutAt(): ?Carbon
    {
        if (blank($this->punch_out_time)) {
            return null;
        }

        return Carbon::parse(
            $this->attendance_date->toDateString().' '.$this->punch_out_time,
            AttendanceCalendar::TIMEZONE,
        );
    }
}
