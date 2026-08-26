<?php

namespace App\Models;

use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditNote extends Model
{
    use SoftDeletes;

    public const TYPE_SALES_RETURN = 'sales_return';

    public const TYPE_RATE_DIFFERENCE = 'rate_difference';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const REJECTED_BY_ROLE_SALES_MANAGER = 'Sales Manager';

    public const REJECTED_BY_ROLE_ADMIN = 'Admin';

    public const EDITED_BY_ROLE_SALES_EMPLOYEE = 'Sales Employee';

    public const EDITED_BY_ROLE_SALES_MANAGER = 'Sales Manager';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const TYPE_LABELS = [
        self::TYPE_SALES_RETURN => 'Sales Return',
        self::TYPE_RATE_DIFFERENCE => 'Rate Difference',
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING_APPROVAL => 'Pending for Manager Approval',
        self::STATUS_APPROVED => 'Approved by Sales Manager',
        self::STATUS_COMPLETED => 'Credit Note Generated',
        self::STATUS_REJECTED => 'Rejected',
    ];

    private const STATUS_TRANSITIONS = [
        self::STATUS_PENDING_APPROVAL => [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_REJECTED => [],
    ];

    protected static function booted(): void
    {
        static::creating(function (CreditNote $creditNote): void {
            if (blank($creditNote->credit_note_no)) {
                $lastNumber = static::withTrashed()
                    ->where('credit_note_no', 'like', 'CN%')
                    ->orderByDesc('credit_note_no')
                    ->value('credit_note_no');

                $creditNote->credit_note_no = 'CN'.str_pad(
                    (string) ($lastNumber === null ? 1 : ((int) substr((string) $lastNumber, 2)) + 1),
                    6,
                    '0',
                    STR_PAD_LEFT,
                );
            }
        });

        static::saving(function (CreditNote $creditNote): void {
            if ($creditNote->exists && $creditNote->isDirty('status') && ! $creditNote->canTransitionTo($creditNote->status)) {
                throw ValidationException::withMessages([
                    'status' => 'This status change is not permitted.',
                ]);
            }
        });
    }

    protected $fillable = [
        'credit_note_no',
        'type',
        'dealer_id',
        'sales_employee_id',
        'bill_reference',
        'credit_note_date',
        'amount',
        'remarks',
        'supporting_document_path',
        'status',
        'approved_by',
        'approved_at',
        'approval_remark',
        'rejected_by',
        'rejected_by_role',
        'rejected_at',
        'rejection_remark',
        'completed_by',
        'completed_at',
        'completion_remark',
        'last_edited_by',
        'last_edited_at',
        'last_edited_by_role',
    ];

    protected function casts(): array
    {
        return [
            'credit_note_date' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }

    public static function businessToday(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return self::TYPE_LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_APPROVAL => 'warning',
            self::STATUS_APPROVED => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function lastEditedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->getOriginal('status') ?? $this->status] ?? [], true);
    }

    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function canBeRejectedByManager(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function canBeRejectedByAdmin(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeCompleted(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isSalesReturn(): bool
    {
        return $this->type === self::TYPE_SALES_RETURN;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function displayStatusLabel(): string
    {
        if ($this->status === self::STATUS_REJECTED) {
            return filled($this->rejected_by_role)
                ? 'Rejected by '.$this->rejected_by_role
                : 'Rejected';
        }

        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function documentUrl(): ?string
    {
        return PublicMediaUrl::fromPublicPath($this->supporting_document_path);
    }

    public function documentIsImage(): bool
    {
        $path = strtolower((string) $this->supporting_document_path);

        return str_ends_with($path, '.jpg')
            || str_ends_with($path, '.jpeg')
            || str_ends_with($path, '.png')
            || str_ends_with($path, '.webp')
            || str_ends_with($path, '.gif');
    }

    public function displayActorRole(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->isAdminUser()) {
            return 'Admin';
        }

        if ($user->isDirectorUser()) {
            return 'Director';
        }

        $jobRole = $user->resolvedJobRole();
        if (filled($jobRole)) {
            return $jobRole;
        }

        $designation = $user->employee?->designation;
        if (filled($designation)) {
            return (string) $designation;
        }

        return $user->roleEnum()->label();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workflowTimeline(): array
    {
        $this->loadMissing([
            'salesEmployee:id,full_name,designation',
            'approvedByUser:id,name,role,job_role,employee_id',
            'approvedByUser.employee:id,full_name,designation',
            'rejectedByUser:id,name,role,job_role,employee_id',
            'rejectedByUser.employee:id,full_name,designation',
            'completedByUser:id,name,role,job_role,employee_id',
            'completedByUser.employee:id,full_name,designation',
            'lastEditedByUser:id,name',
        ]);

        $format = fn ($value): ?string => $value === null
            ? null
            : Carbon::parse($value)->timezone(self::BUSINESS_TIMEZONE)->format('d M Y • h:i A');

        $salesRole = filled($this->salesEmployee?->designation)
            ? (string) $this->salesEmployee->designation
            : 'Sales Employee';

        $steps = [[
            'key' => 'created',
            'label' => 'Credit Note Created',
            'actor' => $this->salesEmployee?->full_name,
            'actor_role' => $salesRole,
            'at' => $format($this->created_at),
            'status_text' => null,
            'remark' => $this->remarks,
            'completed' => true,
            'is_current' => false,
            'is_rejection' => false,
        ]];

        $rejectedBeforeApproval = (filled($this->rejected_at) || $this->status === self::STATUS_REJECTED)
            && blank($this->approved_at);

        if ($rejectedBeforeApproval) {
            $steps[] = [
                'key' => 'rejected',
                'label' => 'Rejected by Sales Manager',
                'actor' => $this->rejectedByUser?->name,
                'actor_role' => $this->rejected_by_role ?: $this->displayActorRole($this->rejectedByUser) ?: 'Sales Manager',
                'at' => $format($this->rejected_at),
                'status_text' => $this->displayStatusLabel(),
                'remark' => $this->rejection_remark,
                'completed' => true,
                'is_current' => true,
                'is_rejection' => true,
            ];

            return $steps;
        }

        $managerApproved = filled($this->approved_at);
        $steps[] = [
            'key' => 'manager_approval',
            'label' => 'Sales Manager Approval',
            'actor' => $managerApproved ? $this->approvedByUser?->name : null,
            'actor_role' => $managerApproved
                ? ($this->displayActorRole($this->approvedByUser) ?? 'Sales Manager')
                : 'Sales Manager',
            'at' => $format($this->approved_at),
            'status_text' => $managerApproved ? 'Approved' : 'Pending',
            'remark' => $this->approval_remark,
            'completed' => $managerApproved,
            'is_current' => $this->status === self::STATUS_PENDING_APPROVAL,
            'is_rejection' => false,
        ];

        $rejectedByAdmin = $this->status === self::STATUS_REJECTED && filled($this->approved_at);
        if ($rejectedByAdmin) {
            $steps[] = [
                'key' => 'rejected',
                'label' => 'Rejected by Admin',
                'actor' => $this->rejectedByUser?->name,
                'actor_role' => $this->rejected_by_role ?: $this->displayActorRole($this->rejectedByUser) ?: 'Admin',
                'at' => $format($this->rejected_at),
                'status_text' => $this->displayStatusLabel(),
                'remark' => $this->rejection_remark,
                'completed' => true,
                'is_current' => true,
                'is_rejection' => true,
            ];

            return $steps;
        }

        $completed = $this->status === self::STATUS_COMPLETED;
        $steps[] = [
            'key' => 'admin_processing',
            'label' => $completed ? 'Credit Note Generated' : 'Admin Processing',
            'actor' => $completed ? $this->completedByUser?->name : null,
            'actor_role' => $completed
                ? ($this->displayActorRole($this->completedByUser) ?? 'Admin')
                : 'Admin',
            'at' => $format($this->completed_at),
            'status_text' => $completed ? 'Completed' : ($managerApproved ? 'Awaiting Admin' : null),
            'remark' => $this->completion_remark,
            'completed' => $completed,
            'is_current' => $this->status === self::STATUS_APPROVED,
            'is_rejection' => false,
        ];

        return $steps;
    }

    public function approve(?int $userId = null, ?string $remark = null): void
    {
        DB::transaction(function () use ($userId, $remark): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== self::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending Credit Notes can be approved.'],
                ]);
            }

            $locked->update([
                'status' => self::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
                'approval_remark' => filled($remark) ? trim($remark) : null,
                'rejected_by' => null,
                'rejected_by_role' => null,
                'rejected_at' => null,
                'rejection_remark' => null,
            ]);

            $this->refresh();
        });
    }

    public function reject(?int $userId = null, ?string $remark = null, ?string $rejectedByRole = null): void
    {
        $remark = trim((string) $remark);

        if ($remark === '') {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remarks are required.'],
            ]);
        }

        DB::transaction(function () use ($userId, $remark, $rejectedByRole): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $allowed = ($rejectedByRole === self::REJECTED_BY_ROLE_ADMIN && $locked->canBeRejectedByAdmin())
                || ($rejectedByRole !== self::REJECTED_BY_ROLE_ADMIN && $locked->canBeRejectedByManager());

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'status' => ['This Credit Note cannot be rejected in its current status.'],
                ]);
            }

            $locked->update([
                'status' => self::STATUS_REJECTED,
                'rejected_by' => $userId,
                'rejected_by_role' => $rejectedByRole,
                'rejected_at' => Carbon::now(self::BUSINESS_TIMEZONE),
                'rejection_remark' => $remark,
            ]);

            $this->refresh();
        });
    }

    public function complete(?int $userId = null, ?string $remark = null): void
    {
        DB::transaction(function () use ($userId, $remark): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeCompleted()) {
                throw ValidationException::withMessages([
                    'status' => ['Only manager-approved Credit Notes can be completed.'],
                ]);
            }

            $locked->update([
                'status' => self::STATUS_COMPLETED,
                'completed_by' => $userId,
                'completed_at' => Carbon::now(self::BUSINESS_TIMEZONE),
                'completion_remark' => filled($remark) ? trim($remark) : null,
            ]);

            $this->refresh();
        });
    }
}
