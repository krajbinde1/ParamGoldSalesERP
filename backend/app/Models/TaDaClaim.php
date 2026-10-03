<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TaDaClaim extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_PAID => 'Paid',
    ];

    protected $fillable = [
        'employee_id',
        'submitter_role',
        'claim_date',
        'from_location',
        'to_location',
        'travel_km',
        'per_km_rate',
        'travel_amount',
        'da_amount',
        'other_expense',
        'total_amount',
        'bill_photo_path',
        'employee_remarks',
        'admin_remark',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'approver_role',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date',
            'travel_km' => 'decimal:2',
            'per_km_rate' => 'decimal:2',
            'travel_amount' => 'decimal:2',
            'da_amount' => 'decimal:2',
            'other_expense' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public static function businessNow(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE);
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function routeLabel(): string
    {
        return sprintf('%s → %s', $this->from_location, $this->to_location);
    }

    public function claimNumber(): string
    {
        $year = $this->claim_date?->format('Y')
            ?? $this->created_at?->timezone(self::BUSINESS_TIMEZONE)->format('Y')
            ?? self::businessNow()->format('Y');

        return sprintf('TA-%s-%03d', $year, $this->id);
    }

    public function requiresDirectorApproval(): bool
    {
        return $this->submitter_role === 'manager';
    }

    public function submitterRoleLabel(): string
    {
        return match ($this->submitter_role) {
            'manager' => 'Manager',
            'director' => 'Director',
            default => 'Sales Employee',
        };
    }

    public function displayStatusLabel(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->requiresDirectorApproval()) {
            return 'Pending Director Approval';
        }

        return self::statusLabel($this->status);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSubmittedByEmployee(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereNull('submitter_role')
                ->orWhere('submitter_role', 'employee');
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSubmittedByManager(Builder $query): Builder
    {
        return $query->where('submitter_role', 'manager');
    }

    public function billPhotoUrl(): ?string
    {
        if (blank($this->bill_photo_path)) {
            return null;
        }

        return url('storage/'.str_replace('\\', '/', $this->bill_photo_path));
    }

    public function canApprove(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canReject(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canMarkPaid(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function approve(?int $userId = null, ?string $approverRole = null): void
    {
        if (! $this->canApprove()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending claims can be approved.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => self::businessNow(),
            'approver_role' => $approverRole,
        ]);
    }

    public function reject(string $adminRemark, ?int $userId = null, ?string $approverRole = null): void
    {
        if (! $this->canReject()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending claims can be rejected.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_REJECTED,
            'admin_remark' => trim($adminRemark),
            'rejected_by' => $userId,
            'rejected_at' => self::businessNow(),
            'approver_role' => $approverRole,
        ]);
    }

    public function markAsPaid(?int $userId = null): void
    {
        if (! $this->canMarkPaid()) {
            throw ValidationException::withMessages([
                'status' => ['Only approved claims can be marked as paid.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_PAID,
            'paid_by' => $userId,
            'paid_at' => self::businessNow(),
        ]);
    }
}
