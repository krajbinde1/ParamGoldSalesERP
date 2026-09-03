<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING_FIRST = 'pending_first_approval';

    public const STATUS_PENDING_SECOND = 'pending_second_approval';

    public const STATUS_APPROVED_FOR_PAYMENT = 'approved_for_payment';

    public const STATUS_REJECTED_FIRST = 'rejected_by_first_approver';

    public const STATUS_REJECTED_SECOND = 'rejected_by_second_approver';

    public const STATUS_PAYMENT_DONE = 'payment_done';

    public const STATUS_LABELS = [
        self::STATUS_PENDING_FIRST => 'Pending First Approval',
        self::STATUS_PENDING_SECOND => 'Pending Second Approval',
        self::STATUS_APPROVED_FOR_PAYMENT => 'Approved for Payment',
        self::STATUS_REJECTED_FIRST => 'Rejected by First Approver',
        self::STATUS_REJECTED_SECOND => 'Rejected by Second Approver',
        self::STATUS_PAYMENT_DONE => 'Payment Done',
    ];

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    protected $fillable = [
        'request_no',
        'vendor_name',
        'vendor_mobile',
        'amount',
        'remark',
        'status',
        'created_by',
        'first_approved_by',
        'first_approver_name',
        'first_approver_role',
        'first_approved_at',
        'first_rejection_remark',
        'second_approved_by',
        'second_approver_name',
        'second_approver_role',
        'second_approved_at',
        'second_rejection_remark',
        'payment_done_by',
        'payment_done_at',
        'payment_remark',
        'payment_proof_path',
        'reminder_count',
        'last_reminded_at',
        'last_reminded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'payment_done_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentRequest $paymentRequest): void {
            $originalStatus = (string) $paymentRequest->getOriginal('status');
            $wasLocked = filled($paymentRequest->getOriginal('first_approved_by'))
                || filled($paymentRequest->getOriginal('first_approved_at'))
                || ($originalStatus !== '' && $originalStatus !== self::STATUS_PENDING_FIRST);

            if (! $wasLocked) {
                return;
            }

            $lockedAttributes = [
                'request_no',
                'vendor_name',
                'vendor_mobile',
                'amount',
                'remark',
                'created_by',
            ];

            if ($paymentRequest->isDirty($lockedAttributes)) {
                throw ValidationException::withMessages([
                    'payment_request' => ['Payment Request cannot be modified after Director approval.'],
                ]);
            }
        });

        static::deleting(function (PaymentRequest $paymentRequest): void {
            if ($paymentRequest->isLockedForAdminEdits()) {
                throw ValidationException::withMessages([
                    'payment_request' => ['Payment Request cannot be deleted after Director approval.'],
                ]);
            }
        });
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function firstApprovedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approved_by');
    }

    public function secondApprovedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by');
    }

    public function paymentDoneByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_done_by');
    }

    public function lastRemindedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reminded_by');
    }

    public function supportingDocuments(): HasMany
    {
        return $this->hasMany(PaymentRequestSupportingDocument::class)->latest('id');
    }

    public function isAwaitingApproval(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_FIRST,
            self::STATUS_PENDING_SECOND,
        ], true);
    }

    /**
     * True once the first Director has approved (or the request has otherwise
     * moved past pending first approval via Director action).
     */
    public function hasAnyDirectorApproval(): bool
    {
        if (filled($this->first_approved_by) || filled($this->first_approved_at)) {
            return true;
        }

        return $this->status !== self::STATUS_PENDING_FIRST;
    }

    /**
     * Admin must not change request data / supporting documents after first Director approval.
     */
    public function isLockedForAdminEdits(): bool
    {
        return $this->hasAnyDirectorApproval();
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function displayStatusLabel(): string
    {
        return self::statusLabel((string) $this->status);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_FIRST, self::STATUS_PENDING_SECOND => 'warning',
            self::STATUS_APPROVED_FOR_PAYMENT => 'success',
            self::STATUS_PAYMENT_DONE => 'info',
            self::STATUS_REJECTED_FIRST, self::STATUS_REJECTED_SECOND => 'danger',
            default => 'gray',
        };
    }

    public function paymentStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PAYMENT_DONE => 'Payment Done',
            self::STATUS_APPROVED_FOR_PAYMENT => 'Pending Payment',
            default => '—',
        };
    }

    public function currentStageLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_FIRST => 'First Approval',
            self::STATUS_PENDING_SECOND => 'Second Approval',
            self::STATUS_APPROVED_FOR_PAYMENT => 'Awaiting Payment',
            self::STATUS_PAYMENT_DONE => 'Payment Done',
            self::STATUS_REJECTED_FIRST => 'Rejected (First)',
            self::STATUS_REJECTED_SECOND => 'Rejected (Second)',
            default => $this->displayStatusLabel(),
        };
    }

    public function currentApproverLabel(): string
    {
        $resolver = app(\App\Services\PaymentRequests\PaymentRequestApproverResolver::class);

        return match ($this->status) {
            self::STATUS_PENDING_FIRST => $resolver->firstApproverDisplayName(),
            self::STATUS_PENDING_SECOND => $resolver->secondApproverDisplayName(),
            default => '—',
        };
    }

    public function firstApprovalStatusLabel(): string
    {
        if ($this->status === self::STATUS_REJECTED_FIRST) {
            return 'Rejected';
        }
        if (filled($this->first_approved_at) && $this->status !== self::STATUS_PENDING_FIRST) {
            return 'Approved';
        }
        if ($this->status === self::STATUS_PENDING_FIRST) {
            return 'Pending';
        }

        return filled($this->first_approved_at) ? 'Approved' : '—';
    }

    public function secondApprovalStatusLabel(): string
    {
        if ($this->status === self::STATUS_REJECTED_SECOND) {
            return 'Rejected';
        }
        if ($this->status === self::STATUS_PENDING_FIRST || $this->status === self::STATUS_REJECTED_FIRST) {
            return '—';
        }
        if ($this->status === self::STATUS_PENDING_SECOND) {
            return 'Pending';
        }
        if (filled($this->second_approved_at)) {
            return 'Approved';
        }

        return '—';
    }

    public function canBeFirstApproved(): bool
    {
        return $this->status === self::STATUS_PENDING_FIRST;
    }

    public function canBeSecondApproved(): bool
    {
        return $this->status === self::STATUS_PENDING_SECOND;
    }

    public function canBeMarkedPaid(): bool
    {
        return $this->status === self::STATUS_APPROVED_FOR_PAYMENT;
    }

    public function isRejected(): bool
    {
        return in_array($this->status, [
            self::STATUS_REJECTED_FIRST,
            self::STATUS_REJECTED_SECOND,
        ], true);
    }

    public static function generateRequestNo(): string
    {
        return DB::transaction(function (): string {
            $latest = static::withTrashed()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('request_no');

            $next = 1;
            if (is_string($latest) && preg_match('/^PR-(\d+)$/', $latest, $matches) === 1) {
                $next = ((int) $matches[1]) + 1;
            }

            return 'PR-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    public function approveFirst(User $actor, string $approverRole): void
    {
        if (! $this->canBeFirstApproved()) {
            throw ValidationException::withMessages([
                'status' => ['This payment request is not awaiting first approval.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_PENDING_SECOND,
            'first_approved_by' => $actor->id,
            'first_approver_name' => $actor->name,
            'first_approver_role' => $approverRole,
            'first_approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'first_rejection_remark' => null,
        ]);
    }

    public function rejectFirst(User $actor, string $approverRole, string $remark): void
    {
        if (! $this->canBeFirstApproved()) {
            throw ValidationException::withMessages([
                'status' => ['This payment request is not awaiting first approval.'],
            ]);
        }

        $remark = trim($remark);
        if (mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'remark' => ['Rejection remark is required (minimum 3 characters).'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_REJECTED_FIRST,
            'first_approved_by' => $actor->id,
            'first_approver_name' => $actor->name,
            'first_approver_role' => $approverRole,
            'first_approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'first_rejection_remark' => $remark,
        ]);
    }

    public function approveSecond(User $actor, string $approverRole): void
    {
        if (! $this->canBeSecondApproved()) {
            throw ValidationException::withMessages([
                'status' => ['This payment request is not awaiting second approval.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_APPROVED_FOR_PAYMENT,
            'second_approved_by' => $actor->id,
            'second_approver_name' => $actor->name,
            'second_approver_role' => $approverRole,
            'second_approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'second_rejection_remark' => null,
        ]);
    }

    public function rejectSecond(User $actor, string $approverRole, string $remark): void
    {
        if (! $this->canBeSecondApproved()) {
            throw ValidationException::withMessages([
                'status' => ['This payment request is not awaiting second approval.'],
            ]);
        }

        $remark = trim($remark);
        if (mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'remark' => ['Rejection remark is required (minimum 3 characters).'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_REJECTED_SECOND,
            'second_approved_by' => $actor->id,
            'second_approver_name' => $actor->name,
            'second_approver_role' => $approverRole,
            'second_approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'second_rejection_remark' => $remark,
        ]);
    }

    public function markPaymentDone(User $actor, string $proofPath, ?string $remark = null): void
    {
        if (! $this->canBeMarkedPaid()) {
            throw ValidationException::withMessages([
                'status' => ['Payment can only be marked done after both approvals.'],
            ]);
        }

        if (blank($proofPath)) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Payment screenshot / proof is required.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_PAYMENT_DONE,
            'payment_done_by' => $actor->id,
            'payment_done_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'payment_remark' => filled($remark) ? trim($remark) : null,
            'payment_proof_path' => $proofPath,
        ]);
    }

    public function paymentProofUrl(): ?string
    {
        if (blank($this->payment_proof_path)) {
            return null;
        }

        return url('storage/'.ltrim(str_replace('\\', '/', $this->payment_proof_path), '/'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function approvalTimeline(): array
    {
        $format = fn ($value): ?string => $value === null
            ? null
            : Carbon::parse($value)->timezone(self::BUSINESS_TIMEZONE)->format('d M Y • h:i A');

        $resolver = app(\App\Services\PaymentRequests\PaymentRequestApproverResolver::class);
        $firstExpected = $resolver->firstApproverDisplayName();
        $secondExpected = $resolver->secondApproverDisplayName();

        $steps = [
            [
                'key' => 'created',
                'label' => 'Request Created',
                'badge' => 'Completed',
                'actor' => $this->createdByUser?->name ?: 'Admin',
                'actor_role' => 'Admin',
                'at' => $format($this->created_at),
                'completed' => true,
                'is_current' => false,
                'is_rejection' => false,
                'not_started' => false,
                'pending' => false,
                'remark' => null,
            ],
        ];

        $firstRejected = $this->status === self::STATUS_REJECTED_FIRST;
        $firstApproved = filled($this->first_approved_at)
            || filled($this->first_approved_by)
            || in_array($this->status, [
                self::STATUS_PENDING_SECOND,
                self::STATUS_APPROVED_FOR_PAYMENT,
                self::STATUS_PAYMENT_DONE,
                self::STATUS_REJECTED_SECOND,
            ], true);
        $firstCurrent = $this->status === self::STATUS_PENDING_FIRST;

        $steps[] = [
            'key' => 'first_approval',
            'label' => 'First Approval',
            'badge' => $firstRejected
                ? 'Rejected'
                : ($firstApproved ? 'Approved' : ($firstCurrent ? 'Pending' : 'Not Started')),
            'actor' => $firstApproved || $firstRejected
                ? ($this->first_approver_name ?: $firstExpected)
                : ($firstCurrent ? $firstExpected : null),
            'actor_role' => 'Director',
            'at' => ($firstApproved || $firstRejected) ? $format($this->first_approved_at) : null,
            'completed' => $firstApproved && ! $firstRejected,
            'is_current' => $firstCurrent,
            'is_rejection' => $firstRejected,
            'not_started' => ! $firstApproved && ! $firstRejected && ! $firstCurrent,
            'pending' => $firstCurrent,
            'remark' => $firstRejected ? $this->first_rejection_remark : null,
        ];

        if ($firstRejected) {
            return $steps;
        }

        $secondRejected = $this->status === self::STATUS_REJECTED_SECOND;
        $secondApproved = filled($this->second_approved_at)
            || filled($this->second_approved_by)
            || in_array($this->status, [
                self::STATUS_APPROVED_FOR_PAYMENT,
                self::STATUS_PAYMENT_DONE,
            ], true);
        $secondCurrent = $this->status === self::STATUS_PENDING_SECOND;

        $steps[] = [
            'key' => 'second_approval',
            'label' => 'Second Approval',
            'badge' => $secondRejected
                ? 'Rejected'
                : ($secondApproved ? 'Approved' : ($secondCurrent ? 'Pending' : 'Not Started')),
            'actor' => $secondApproved || $secondRejected
                ? ($this->second_approver_name ?: $secondExpected)
                : ($secondCurrent ? $secondExpected : null),
            'actor_role' => 'Director',
            'at' => ($secondApproved || $secondRejected) ? $format($this->second_approved_at) : null,
            'completed' => $secondApproved && ! $secondRejected,
            'is_current' => $secondCurrent,
            'is_rejection' => $secondRejected,
            'not_started' => ! $secondApproved && ! $secondRejected && ! $secondCurrent,
            'pending' => $secondCurrent,
            'remark' => $secondRejected ? $this->second_rejection_remark : null,
        ];

        if ($secondRejected) {
            return $steps;
        }

        $approvedForPayment = in_array($this->status, [
            self::STATUS_APPROVED_FOR_PAYMENT,
            self::STATUS_PAYMENT_DONE,
        ], true);
        $approvedCurrent = $this->status === self::STATUS_APPROVED_FOR_PAYMENT;

        $steps[] = [
            'key' => 'approved_for_payment',
            'label' => 'Approved for Payment',
            'badge' => $approvedForPayment
                ? ($this->status === self::STATUS_PAYMENT_DONE ? 'Completed' : 'Current')
                : ($secondApproved ? 'Pending' : 'Not Started'),
            'actor' => $approvedForPayment ? ($this->second_approver_name ?: $secondExpected) : null,
            'actor_role' => $approvedForPayment ? 'Director' : null,
            'at' => $approvedForPayment ? $format($this->second_approved_at) : null,
            'completed' => $approvedForPayment,
            'is_current' => $approvedCurrent,
            'is_rejection' => false,
            'not_started' => ! $approvedForPayment && ! $secondApproved,
            'pending' => $secondApproved && ! $approvedForPayment,
            'remark' => null,
        ];

        // Prefer clearer badge labels for Approved for Payment stage.
        if ($approvedForPayment && $this->status === self::STATUS_APPROVED_FOR_PAYMENT) {
            $steps[array_key_last($steps)]['badge'] = 'Awaiting Payment';
        } elseif ($approvedForPayment) {
            $steps[array_key_last($steps)]['badge'] = 'Completed';
        }

        $paidDone = $this->status === self::STATUS_PAYMENT_DONE;

        $steps[] = [
            'key' => 'payment_done',
            'label' => 'Payment Done',
            'badge' => $paidDone ? 'Completed' : ($approvedForPayment ? 'Pending' : 'Not Started'),
            'actor' => $paidDone ? ($this->paymentDoneByUser?->name) : null,
            'actor_role' => $paidDone ? 'Admin' : null,
            'at' => $paidDone ? $format($this->payment_done_at) : null,
            'completed' => $paidDone,
            'is_current' => false,
            'is_rejection' => false,
            'not_started' => ! $paidDone && ! $approvedForPayment,
            'pending' => $approvedForPayment && ! $paidDone,
            'remark' => $paidDone ? $this->payment_remark : null,
        ];

        return $steps;
    }
}