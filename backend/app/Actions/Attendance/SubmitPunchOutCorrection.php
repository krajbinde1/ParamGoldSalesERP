<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Models\User;
use App\Services\Attendance\AttendancePunchWorkflow;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class SubmitPunchOutCorrection
{
    public function __construct(
        private readonly AttendancePunchWorkflow $workflow,
    ) {}

    /**
     * @param  array{
     *     actual_punch_out_date: string,
     *     actual_punch_out_time: string,
     *     reason: string,
     *     reason_note?: string|null
     * }  $payload
     */
    public function execute(Attendance $attendance, User $user, array $payload, ?Carbon $now = null): AttendancePunchOutCorrection
    {
        $now ??= Attendance::businessNow();

        if (filled($attendance->punch_out_time)) {
            throw ValidationException::withMessages([
                'punch_out' => 'Already punched out.',
            ]);
        }

        if ($this->workflow->pendingCorrection($attendance) !== null) {
            throw ValidationException::withMessages([
                'punch_out' => 'A punch-out correction is already pending approval.',
            ]);
        }

        if (! $this->workflow->isMoreThan24Hours($attendance, $now)) {
            throw ValidationException::withMessages([
                'punch_out' => 'Use Punch Out instead of a correction request.',
            ]);
        }

        $reason = (string) $payload['reason'];
        if (! array_key_exists($reason, AttendancePunchOutCorrection::REASON_LABELS)) {
            throw ValidationException::withMessages([
                'reason' => 'A valid reason is required.',
            ]);
        }

        $note = trim((string) ($payload['reason_note'] ?? ''));
        if ($reason === AttendancePunchOutCorrection::REASON_OTHER && $note === '') {
            throw ValidationException::withMessages([
                'reason_note' => 'Please enter a reason.',
            ]);
        }

        $requested = Carbon::parse(
            $payload['actual_punch_out_date'].' '.$payload['actual_punch_out_time'],
            AttendanceCalendar::TIMEZONE,
        );

        $punchIn = $attendance->punchInAt();
        if ($punchIn !== null && $requested->lessThanOrEqualTo($punchIn)) {
            throw ValidationException::withMessages([
                'actual_punch_out_time' => 'Actual punch out must be after punch in.',
            ]);
        }

        if ($requested->greaterThan($now)) {
            throw ValidationException::withMessages([
                'actual_punch_out_time' => 'Actual punch out cannot be in the future.',
            ]);
        }

        $correction = AttendancePunchOutCorrection::query()->create([
            'attendance_id' => $attendance->id,
            'requested_by' => $user->id,
            'requested_punch_out_at' => $requested,
            'reason' => $reason,
            'reason_note' => $note !== '' ? $note : null,
            'status' => AttendancePunchOutCorrection::STATUS_PENDING,
        ]);

        $attendance->update([
            'punch_out_correction_status' => AttendancePunchOutCorrection::STATUS_PENDING,
        ]);

        return $correction->fresh() ?? $correction;
    }
}
