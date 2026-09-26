<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\AttendancePunchOutCorrection;
use App\Services\Attendance\AttendancePunchWorkflow;
use App\Services\EmployeeRouteAnalysisService;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class RecordLatePunchOut
{
    public function __construct(
        private readonly AttendancePunchWorkflow $workflow,
    ) {}

    /**
     * @param  array{
     *     punch_out_latitude: mixed,
     *     punch_out_longitude: mixed,
     *     punch_out_location: string,
     *     punch_out_photo: string,
     *     late_punch_out_reason: string,
     *     late_punch_out_reason_note?: string|null
     * }  $payload
     */
    public function execute(Attendance $attendance, array $payload, ?Carbon $now = null): Attendance
    {
        $now ??= Attendance::businessNow();

        if (filled($attendance->punch_out_time)) {
            throw ValidationException::withMessages([
                'punch_out' => 'Already punched out.',
            ]);
        }

        if ($this->workflow->isMoreThan24Hours($attendance, $now)) {
            throw ValidationException::withMessages([
                'punch_out' => 'Request Punch Out Correction',
            ]);
        }

        $reason = (string) $payload['late_punch_out_reason'];
        if (! array_key_exists($reason, AttendancePunchOutCorrection::REASON_LABELS)) {
            throw ValidationException::withMessages([
                'late_punch_out_reason' => 'A valid late punch-out reason is required.',
            ]);
        }

        $note = trim((string) ($payload['late_punch_out_reason_note'] ?? ''));
        if ($reason === AttendancePunchOutCorrection::REASON_OTHER && $note === '') {
            throw ValidationException::withMessages([
                'late_punch_out_reason_note' => 'Please enter a reason.',
            ]);
        }

        $attendance->update([
            'punch_out_time' => $now->timezone(AttendanceCalendar::TIMEZONE)->format('H:i:s'),
            'punch_out_at' => $now->copy()->timezone(AttendanceCalendar::TIMEZONE),
            'punch_out_latitude' => $payload['punch_out_latitude'],
            'punch_out_longitude' => $payload['punch_out_longitude'],
            'punch_out_location' => $payload['punch_out_location'],
            'punch_out_photo' => $payload['punch_out_photo'],
            'is_late_punch_out' => true,
            'late_punch_out_reason' => $reason,
            'late_punch_out_reason_note' => $note !== '' ? $note : null,
            'punch_out_correction_status' => null,
            'total_working_minutes' => $attendance->punchInAt()?->diffInMinutes($now),
        ]);

        $fresh = $attendance->fresh();
        app(EmployeeRouteAnalysisService::class)->recalculateAndPersistDistance($fresh);

        return $fresh->fresh();
    }
}
