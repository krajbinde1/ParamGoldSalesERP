<?php

namespace App\Actions\Attendance;

use App\Models\AttendancePunchOutCorrection;
use App\Models\User;
use App\Support\AttendanceCalendar;
use App\Support\PunchOutCorrectionCutoff;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class RejectPunchOutCorrection
{
    public function execute(
        AttendancePunchOutCorrection $correction,
        User $reviewer,
        ?string $remark = null,
        ?Carbon $now = null,
    ): AttendancePunchOutCorrection {
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

        $now ??= Carbon::now(AttendanceCalendar::TIMEZONE);

        $correction->update([
            'status' => AttendancePunchOutCorrection::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => $now,
            'review_remark' => filled($remark) ? trim($remark) : null,
        ]);

        $attendance = $correction->attendance;
        if ($attendance !== null && blank($attendance->punch_out_time)) {
            $attendance->update([
                'punch_out_correction_status' => AttendancePunchOutCorrection::STATUS_REJECTED,
            ]);
        }

        return $correction->fresh() ?? $correction;
    }
}
