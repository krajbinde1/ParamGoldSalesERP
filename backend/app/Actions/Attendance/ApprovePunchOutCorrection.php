<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Models\User;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use App\Support\PunchOutCorrectionCutoff;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ApprovePunchOutCorrection
{
    public function execute(
        AttendancePunchOutCorrection $correction,
        User $reviewer,
        ?string $remark = null,
        ?Carbon $now = null,
    ): Attendance {
        if (! $correction->isPending()) {
            throw ValidationException::withMessages([
                'correction' => 'This punch-out correction is no longer pending.',
            ]);
        }

        if (! PunchOutCorrectionCutoff::includes($correction)) {
            throw ValidationException::withMessages([
                'correction' => 'This punch-out correction is outside the approval workflow.',
            ]);
        }

        $attendance = $correction->attendance;
        if ($attendance === null) {
            throw ValidationException::withMessages([
                'correction' => 'Attendance record was not found.',
            ]);
        }

        if (filled($attendance->punch_out_time)) {
            throw ValidationException::withMessages([
                'correction' => 'Attendance already has a punch out.',
            ]);
        }

        $now ??= Attendance::businessNow();
        $requested = $correction->requestedPunchOutAtIst()
            ?? Carbon::parse($correction->requested_punch_out_at, AttendanceCalendar::TIMEZONE);

        $correction->update([
            'status' => AttendancePunchOutCorrection::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => $now,
            'review_remark' => filled($remark) ? trim($remark) : null,
        ]);

        $attendance->update([
            'punch_out_time' => $requested->format('H:i:s'),
            'punch_out_at' => $requested,
            'is_late_punch_out' => true,
            'late_punch_out_reason' => $correction->reason,
            'late_punch_out_reason_note' => $correction->reason_note,
            'punch_out_correction_status' => AttendancePunchOutCorrection::STATUS_APPROVED,
        ]);

        $fresh = $attendance->fresh();
        app(EmployeeRouteAnalysisService::class)->recalculateAndPersistDistance($fresh);

        return $fresh->fresh();
    }
}
