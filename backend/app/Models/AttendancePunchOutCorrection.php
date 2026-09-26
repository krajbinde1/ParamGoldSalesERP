<?php

namespace App\Models;

use App\Support\AttendanceCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AttendancePunchOutCorrection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const REASON_FORGOT = 'forgot_to_punch_out';

    public const REASON_TRAVELLING = 'travelling';

    public const REASON_OTHER = 'other';

    public const REASON_LABELS = [
        self::REASON_FORGOT => 'Forgot to Punch Out',
        self::REASON_TRAVELLING => 'Travelling',
        self::REASON_OTHER => 'Other',
    ];

    protected $fillable = [
        'attendance_id',
        'requested_by',
        'requested_punch_out_at',
        'reason',
        'reason_note',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_remark',
    ];

    protected function casts(): array
    {
        return [
            'requested_punch_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? (string) $this->reason;
    }

    public function requestedPunchOutAtIst(): ?Carbon
    {
        return $this->requested_punch_out_at?->timezone(AttendanceCalendar::TIMEZONE);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function reasonOptions(): array
    {
        return collect(self::REASON_LABELS)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['requestedByUser:id,name', 'reviewedByUser:id,name']);
        $requested = $this->requestedPunchOutAtIst();

        return [
            'id' => $this->id,
            'attendance_id' => $this->attendance_id,
            'requested_punch_out_at' => $requested?->toIso8601String(),
            'requested_punch_out_date' => $requested?->toDateString(),
            'requested_punch_out_time' => $requested?->format('H:i:s'),
            'requested_punch_out_time_label' => $requested?->format('d M Y h:i A'),
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel(),
            'reason_note' => $this->reason_note,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->requestedByUser?->name,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_by_name' => $this->reviewedByUser?->name,
            'reviewed_at' => $this->reviewed_at?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String(),
            'reviewed_at_label' => $this->reviewed_at?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y h:i A'),
            'review_remark' => $this->review_remark,
            'created_at' => $this->created_at?->timezone(AttendanceCalendar::TIMEZONE)->toIso8601String(),
        ];
    }
}
