<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Support\PunchOutCorrectionCutoff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AttendancePunchWorkflow
{
    public const MAX_NORMAL_PUNCH_OUT_MINUTES = 24 * 60;

    /**
     * Every attendance that still has no punch out, newest first.
     * An old open row is not automatically "today's" session.
     *
     * @return Collection<int, Attendance>
     */
    public function openAttendances(int $employeeId): Collection
    {
        return Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('punch_in_time')
            ->whereNull('punch_out_time')
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->get();
    }

    public function openAttendance(int $employeeId): ?Attendance
    {
        return $this->openAttendances($employeeId)->first();
    }

    public function previousDayOpenAttendance(int $employeeId, ?Carbon $today = null): ?Attendance
    {
        $today = ($today ?? Attendance::businessToday())->toDateString();

        return $this->openAttendances($employeeId)
            ->first(fn (Attendance $attendance): bool => $attendance->attendance_date->toDateString() < $today);
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
        $punchIn = $attendance->punchInAt();
        if ($punchIn === null) {
            return false;
        }

        $now ??= Attendance::businessNow();

        // Elapsed clock time in Asia/Kolkata, not a calendar-date comparison.
        return $punchIn->copy()
            ->addMinutes(self::MAX_NORMAL_PUNCH_OUT_MINUTES)
            ->lte($now);
    }

    public function pendingCorrection(Attendance $attendance): ?AttendancePunchOutCorrection
    {
        if ($attendance->relationLoaded('punchOutCorrections')) {
            return $attendance->punchOutCorrections
                ->first(fn (AttendancePunchOutCorrection $row): bool => $row->isActionablePending());
        }

        return PunchOutCorrectionCutoff::constrainPending($attendance->punchOutCorrections()->getQuery())
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

        $opens = $this->openAttendances($employeeId);
        $expired = $opens->first(
            fn (Attendance $attendance): bool => $this->isMoreThan24Hours($attendance, $now),
        );
        $active = $opens->first(
            fn (Attendance $attendance): bool => ! $this->isMoreThan24Hours($attendance, $now),
        );

        $pendingCorrection = $expired instanceof Attendance
            ? $this->pendingCorrection($expired)
            : null;
        $correctionPending = $pendingCorrection !== null;
        $correctionRequired = $expired instanceof Attendance && ! $correctionPending;
        $activeIsPreviousDay = $active instanceof Attendance
            && $active->attendance_date->toDateString() < $today;
        $lateReasonRequired = $activeIsPreviousDay && blank($active->punch_out_time);
        $punchOutAllowed = $active instanceof Attendance && blank($active->punch_out_time);
        $punchInAllowed = $todayAttendance === null && $opens->isEmpty();

        // Expired open attendance must not be presented as the current punched-in session.
        $display = null;
        if ($active instanceof Attendance) {
            $display = $active;
        } elseif ($todayAttendance !== null && filled($todayAttendance->punch_out_time)) {
            $display = $todayAttendance;
        }

        $elapsedSource = $active ?? $expired;

        return [
            'attendance' => $display,
            'today_attendance' => $todayAttendance,
            'open_attendance' => $active ?? $expired,
            'active_open_attendance' => $active,
            'expired_open_attendance' => $expired,
            'previous_punch_out_pending' => $expired instanceof Attendance || $activeIsPreviousDay,
            'punch_in_allowed' => $punchInAllowed,
            'punch_out_allowed' => $punchOutAllowed,
            'late_punch_out_reason_required' => $lateReasonRequired,
            'punch_out_correction_required' => $correctionRequired,
            'punch_out_correction_pending' => $correctionPending,
            'is_current_session' => $active instanceof Attendance,
            'open_elapsed_minutes' => $elapsedSource instanceof Attendance
                ? $this->elapsedMinutes($elapsedSource, $now)
                : 0,
            'pending_correction' => $pendingCorrection,
            'late_punch_out_reasons' => AttendancePunchOutCorrection::reasonOptions(),
        ];
    }
}
