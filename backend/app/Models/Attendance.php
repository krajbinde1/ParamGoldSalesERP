<?php

namespace App\Models;

use App\Services\Attendance\AttendanceStatusCalculator;
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
        AttendanceStatusCalculator::STATUS_PUNCHED_IN => 'Punched In',
        AttendanceStatusCalculator::STATUS_PRESENT => 'Present',
        AttendanceStatusCalculator::STATUS_HALF_DAY => 'Half Day',
        AttendanceStatusCalculator::STATUS_ABSENT => 'Absent',
        AttendanceStatusCalculator::STATUS_LEAVE => 'Leave',
        AttendanceStatusCalculator::STATUS_WEEKLY_OFF => 'Weekly Off',
    ];

    public const APPROVAL_STATUS_LABELS = [
        'Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected',
    ];

    /** @deprecated Use AttendanceStatusCalculator::PRESENT_MINUTES */
    public const ADMIN_MIN_PRESENT_MINUTES = AttendanceStatusCalculator::PRESENT_MINUTES;

    protected static function booted(): void
    {
        static::saving(function (Attendance $attendance): void {
            $calculator = app(AttendanceStatusCalculator::class);

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
            } elseif (filled($attendance->punch_in_time) && blank($attendance->punch_out_time)) {
                $attendance->working_hours = null;
                $attendance->total_working_minutes = null;
            } else {
                $attendance->working_hours = null;
            }

            if ($calculator->shouldAutoRecalculate($attendance->attendance_status)) {
                $attendance->attendance_status = $calculator->calculateForAttendance($attendance);
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

    /**
     * @return array{
     *     month: int,
     *     year: int,
     *     working_days: int,
     *     present_days: int,
     *     half_days: int,
     *     absent_days: int,
     *     punch_in_days: int,
     *     punch_out_days: int
     * }
     */
    public static function monthlySummary(int $employeeId, int $month, int $year): array
    {
        return self::buildMonthlySummary($employeeId, $month, $year);
    }

    /**
     * @return array{
     *     employee_id: int,
     *     month: int,
     *     year: int,
     *     working_days: int,
     *     present_days: int,
     *     half_days: int,
     *     absent_days: int
     * }
     */
    public static function adminEmployeeMonthlySummary(int $employeeId, int $month, int $year): array
    {
        $summary = self::buildMonthlySummary($employeeId, $month, $year);

        return [
            'employee_id' => $employeeId,
            'month' => $summary['month'],
            'year' => $summary['year'],
            'working_days' => $summary['working_days'],
            'present_days' => $summary['present_days'],
            'half_days' => $summary['half_days'],
            'absent_days' => $summary['absent_days'],
        ];
    }

    /**
     * @return array{
     *     month: int,
     *     year: int,
     *     working_days: int,
     *     present_days: int,
     *     half_days: int,
     *     absent_days: int,
     *     punch_in_days: int,
     *     punch_out_days: int
     * }
     */
    private static function buildMonthlySummary(int $employeeId, int $month, int $year): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, AttendanceCalendar::TIMEZONE)->startOfDay();
        $periodEnd = AttendanceCalendar::periodEndForMonth($month, $year);
        $workingDays = AttendanceCalendar::workingDaysInPeriod($periodStart, $periodEnd);
        $calculator = app(AttendanceStatusCalculator::class);

        $records = self::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $periodStart->toDateString())
            ->whereDate('attendance_date', '<=', $periodEnd->toDateString())
            ->get()
            ->groupBy(fn (self $attendance): string => $attendance->attendance_date->toDateString());

        $presentDays = 0;
        $halfDays = 0;
        $absentDays = 0;
        $openPunchToday = 0;

        for ($date = $periodStart->copy(); $date->lte($periodEnd); $date->addDay()) {
            if ($date->isSunday()) {
                continue;
            }

            $dayRecords = $records->get($date->toDateString(), collect());
            $best = $dayRecords
                ->sortByDesc(fn (self $attendance): int => $attendance->workingDurationMinutes() ?? -1)
                ->first();

            $classification = $calculator->classifyWorkingDay($best, $date);

            match ($classification) {
                AttendanceStatusCalculator::STATUS_PRESENT => $presentDays++,
                AttendanceStatusCalculator::STATUS_HALF_DAY => $halfDays++,
                AttendanceStatusCalculator::STATUS_PUNCHED_IN => $openPunchToday++,
                AttendanceStatusCalculator::STATUS_LEAVE,
                AttendanceStatusCalculator::STATUS_WEEKLY_OFF => null,
                default => $absentDays++,
            };
        }

        $flatRecords = $records->flatten(1);

        return [
            'month' => $month,
            'year' => $year,
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'half_days' => $halfDays,
            'absent_days' => $absentDays,
            'punch_in_days' => $flatRecords->filter(fn (self $attendance): bool => filled($attendance->punch_in_time))->count(),
            'punch_out_days' => $flatRecords->filter(fn (self $attendance): bool => filled($attendance->punch_out_time))->count(),
        ];
    }

    public function workingDurationMinutes(): ?int
    {
        if (blank($this->punch_in_time) || blank($this->punch_out_time)) {
            return null;
        }

        if ($this->total_working_minutes !== null && $this->total_working_minutes >= 0) {
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
