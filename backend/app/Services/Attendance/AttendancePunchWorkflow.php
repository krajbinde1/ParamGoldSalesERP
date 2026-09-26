<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;

final class AttendancePunchWorkflow
{
    public const MAX_NORMAL_PUNCH_OUT_MINUTES = 24 * 60;

    public function openAttendance(int $employeeId): ?Attendance
    {
        return Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('punch_in_time')
            ->whereNull('punch_out_time')
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->first();
    }

    public function previousDayOpenAttendance(int $employeeId, ?Carbon $today = null): ?Attendance
    {
        $today = ($today ?? Attendance::businessToday())->toDateString();
        $open = $this->openAttendance($employeeId);

        if ($open === null) {
            return null;
        }

        return $open->attendance_date->toDateString() < $today ? $open : null;
    }

    public function elapsedMinutes(Attendance $attendance, ?Carbon $now = null): int
    {
        $punchIn = $attendance->punchInAt();
        if ($punchIn === null) {
            return 0;
        }

        $now ??= Attendance::businessNow();
        $minutes = $punchIn->diffInMinutes($now, false);

        return max(0, (int) $minutes);
    }

    public function isMoreThan24Hours(Attendance $attendance, ?Carbon $now = null): bool
    {
        return $this->elapsedMinutes($attendance, $now) >= self::MAX_NORMAL_PUNCH_OUT_MINUTES;
    }

    public function pendingCorrection(Attendance $attendance): ?AttendancePunchOutCorrection
    {
        if ($attendance->relationLoaded('punchOutCorrections')) {
            return $attendance->punchOutCorrections
                ->first(fn (AttendancePunchOutCorrection $row): bool => $row->isPending());
        }

        return $attendance->punchOutCorrections()
            ->where('status', AttendancePunchOutCorrection::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function todayState(int $employeeId): array
    {
        $today = Attendance::businessToday()->toDateString();
        $now = Attendance::businessNow();

        $todayAttendance = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $today)
            ->first();

        $previousOpen = $this->previousDayOpenAttendance($employeeId);
        $open = $previousOpen ?? (
            $todayAttendance !== null && blank($todayAttendance->punch_out_time)
                ? $todayAttendance
                : null
        );

        $pendingCorrection = $open ? $this->pendingCorrection($open) : null;
        $elapsed = $open ? $this->elapsedMinutes($open, $now) : 0;
        $over24 = $open !== null && $elapsed >= self::MAX_NORMAL_PUNCH_OUT_MINUTES;
        $dateChanged = $previousOpen !== null;
        $correctionPending = $pendingCorrection !== null;
        $correctionRequired = $over24 && ! $correctionPending;
        $lateReasonRequired = $dateChanged && ! $over24 && $open !== null && blank($open->punch_out_time);
        $punchOutAllowed = $open !== null
            && blank($open->punch_out_time)
            && ! $over24
            && ! $correctionPending;
        $punchInAllowed = $todayAttendance === null && $previousOpen === null;

        $display = $previousOpen ?? $todayAttendance;

        return [
            'attendance' => $display,
            'today_attendance' => $todayAttendance,
            'open_attendance' => $open,
            'previous_punch_out_pending' => $previousOpen !== null,
            'punch_in_allowed' => $punchInAllowed,
            'punch_out_allowed' => $punchOutAllowed,
            'late_punch_out_reason_required' => $lateReasonRequired,
            'punch_out_correction_required' => $correctionRequired,
            'punch_out_correction_pending' => $correctionPending,
            'open_elapsed_minutes' => $elapsed,
            'pending_correction' => $pendingCorrection,
            'late_punch_out_reasons' => AttendancePunchOutCorrection::reasonOptions(),
        ];
    }
}
