<?php

namespace App\Models;

use App\Services\Orders\OrderBillingTransportCalculator;
use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_REVERTED_TO_MANAGER = 'reverted_to_manager';

    public const STATUS_PENDING_FOR_BILLING = 'pending_for_billing';

    public const STATUS_BILLED = 'billed';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_REJECTED = 'rejected';

    public const REJECTED_BY_ROLE_SALES_MANAGER = 'Sales Manager';

    public const REJECTED_BY_ROLE_ADMIN = 'Admin';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending for Manager Approval',
        'approved' => 'Approved by Sales Manager',
        'on_hold' => 'On Hold',
        'reverted_to_manager' => 'Returned by Production',
        'pending_for_billing' => 'Pending for Billing',
        'billed' => 'Billed',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Placed orders still in the active workflow (not dispatched, rejected, cancelled, or draft).
     *
     * @return list<string>
     */
    public static function activeNonDispatchedStatuses(): array
    {
        return [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_ON_HOLD,
            self::STATUS_REVERTED_TO_MANAGER,
            self::STATUS_PENDING_FOR_BILLING,
            self::STATUS_BILLED,
        ];
    }

    public function scopeActiveNonDispatched(Builder $query): Builder
    {
        return $query->whereIn('status', self::activeNonDispatchedStatuses());
    }

    /**
     * Orders that have entered accounting receivables (billed once; dispatch does not add again).
     *
     * @return list<string>
     */
    public static function billedReceivableStatuses(): array
    {
        return [
            self::STATUS_BILLED,
            self::STATUS_DISPATCHED,
            'delivered',
        ];
    }

    /**
     * Active orders that are not yet billed and must not affect current outstanding.
     *
     * @return list<string>
     */
    public static function unbilledExposureStatuses(): array
    {
        return [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_ON_HOLD,
            self::STATUS_REVERTED_TO_MANAGER,
            self::STATUS_PENDING_FOR_BILLING,
        ];
    }

    private const STATUS_TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        // Billing requires Production Supervisor "Send for Bill" first.
        'approved' => ['pending_for_billing', 'rejected', 'on_hold', 'reverted_to_manager'],
        'on_hold' => ['approved', 'rejected'],
        'reverted_to_manager' => ['approved', 'rejected'],
        'pending_for_billing' => ['billed', 'rejected'],
        'billed' => ['dispatched', 'rejected'],
        'dispatched' => ['rejected'],
        'rejected' => [],
        'delivered' => [],
        'cancelled' => [],
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->order_no)) {
                $lastNumber = static::withTrashed()
                    ->where('order_no', 'like', 'ORD%')
                    ->orderByDesc('order_no')
                    ->value('order_no');

                $order->order_no = 'ORD'.str_pad((string) ($lastNumber === null ? 1 : ((int) substr($lastNumber, 3)) + 1), 6, '0', STR_PAD_LEFT);
            }
        });

        static::saving(function (Order $order): void {
            if ($order->exists && $order->isDirty('status') && ! $order->canTransitionTo($order->status)) {
                throw ValidationException::withMessages([
                    'status' => 'This status change is not permitted.',
                ]);
            }
        });
    }

    protected $fillable = [
        'order_no', 'order_date', 'dealer_id', 'sales_employee_id', 'payment_type',
        'remarks', 'status', 'subtotal', 'discount_amount', 'gst_amount', 'grand_total',
        'unrounded_grand_total', 'round_off',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_by_role', 'rejected_at', 'rejection_remark',
        'last_edited_by', 'last_edited_at', 'last_edited_by_role',
        'held_by', 'held_at', 'hold_remark', 'hold_return_status',
        'hold_released_by', 'hold_released_at',
        'reverted_by', 'reverted_at', 'revert_remark',
        'reapproved_by', 'reapproved_at',
        'sent_for_bill_by', 'sent_for_bill_at', 'transport_remark',
        'billed_by', 'billed_at', 'bill_path', 'bill_number', 'bill_date', 'billing_remark',
        'dispatched_by', 'dispatched_at', 'dispatch_date', 'dispatch_remark',
        'transport_type', 'transport_amount', 'transport_charge_type', 'original_grand_total', 'transport_adjustment',
        'transporter_name', 'vehicle_id', 'vehicle_number', 'lr_number', 'lr_document_path',
        'received_copy_path', 'received_copy_uploaded_by', 'received_copy_uploaded_at',
        'subtotal_before_transport', 'taxable_amount_after_transport',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'dispatch_date' => 'date',
            'bill_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'unrounded_grand_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'transport_amount' => 'decimal:2',
            'original_grand_total' => 'decimal:2',
            'transport_adjustment' => 'decimal:2',
            'subtotal_before_transport' => 'decimal:2',
            'taxable_amount_after_transport' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_edited_at' => 'datetime',
            'held_at' => 'datetime',
            'hold_released_at' => 'datetime',
            'reverted_at' => 'datetime',
            'reapproved_at' => 'datetime',
            'sent_for_bill_at' => 'datetime',
            'billed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_copy_uploaded_at' => 'datetime',
        ];
    }

    public static function businessToday(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_employee_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function lastEditedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function heldByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function holdReleasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hold_released_by');
    }

    public function revertedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function reapprovedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reapproved_by');
    }

    public function workflowEvents(): HasMany
    {
        return $this->hasMany(OrderWorkflowEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function billedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_by');
    }

    public function sentForBillByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_for_bill_by');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dispatchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedCopyUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_copy_uploaded_by');
    }

    public function editPermissionRequests(): HasMany
    {
        return $this->hasMany(OrderEditPermissionRequest::class)->orderByDesc('id');
    }

    /**
     * Display-only short order number for Admin / Production Supervisor lists.
     * Example: PG-20260813-0001 → PG-0001. Does not change stored order_no.
     */
    public function shortOrderNo(): string
    {
        $orderNo = (string) $this->order_no;

        if (preg_match('/^([A-Za-z]+)-(\d{8})-(\d+)$/', $orderNo, $matches) === 1) {
            return $matches[1].'-'.$matches[3];
        }

        return $orderNo;
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public static function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    public function displayStatusLabel(): string
    {
        if ($this->status === self::STATUS_REJECTED) {
            return match ($this->rejected_by_role) {
                self::REJECTED_BY_ROLE_ADMIN => 'Rejected by Admin',
                self::REJECTED_BY_ROLE_SALES_MANAGER => 'Rejected by Sales Manager',
                default => 'Rejected',
            };
        }

        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'gray',
            'pending_approval' => 'amber',
            'approved' => 'success',
            'on_hold' => 'warning',
            'reverted_to_manager' => 'info',
            'pending_for_billing' => 'warning',
            'billed' => 'indigo',
            'dispatched' => 'info',
            'delivered' => 'primary',
            'rejected', 'cancelled' => 'danger',
            default => 'gray',
        };
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->getOriginal('status') ?? $this->status] ?? [], true);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_REVERTED_TO_MANAGER,
        ], true);
    }

    public function isDispatchedLocked(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    public function openEditPermissionRequest(): ?OrderEditPermissionRequest
    {
        $this->loadMissing('editPermissionRequests');

        return $this->editPermissionRequests
            ->first(fn (OrderEditPermissionRequest $request): bool => in_array($request->status, [
                OrderEditPermissionRequest::STATUS_PENDING,
                OrderEditPermissionRequest::STATUS_APPROVED,
                OrderEditPermissionRequest::STATUS_ADMIN_APPROVED,
            ], true));
    }

    public function approvedUnusedEditPermission(): ?OrderEditPermissionRequest
    {
        $this->loadMissing('editPermissionRequests');

        return $this->editPermissionRequests
            ->first(fn (OrderEditPermissionRequest $request): bool => $request->isApprovedUnused());
    }

    public function canRequestDispatchedEditPermission(): bool
    {
        if (! $this->isDispatchedLocked()) {
            return false;
        }

        return ! OrderEditPermissionRequest::query()
            ->where('order_id', $this->id)
            ->open()
            ->exists();
    }

    public function hasApprovedUnusedEditPermission(): bool
    {
        if (! $this->isDispatchedLocked()) {
            return false;
        }

        if ($this->relationLoaded('editPermissionRequests')) {
            return $this->editPermissionRequests
                ->contains(fn (OrderEditPermissionRequest $request): bool => $request->isApprovedUnused());
        }

        return OrderEditPermissionRequest::query()
            ->where('order_id', $this->id)
            ->approvedUnused()
            ->exists();
    }

    public function hasPendingEditPermission(): bool
    {
        if (! $this->isDispatchedLocked()) {
            return false;
        }

        if ($this->relationLoaded('editPermissionRequests')) {
            return $this->editPermissionRequests
                ->contains(fn (OrderEditPermissionRequest $request): bool => $request->isPending());
        }

        return OrderEditPermissionRequest::query()
            ->where('order_id', $this->id)
            ->pending()
            ->exists();
    }

    /**
     * @return list<OrderEditPermissionRequest>
     */
    public function usedEditPermissionAudits(): array
    {
        $this->loadMissing([
            'editPermissionRequests.requestedByUser:id,name',
            'editPermissionRequests.reviewedByUser:id,name',
            'editPermissionRequests.adminReviewedByUser:id,name',
            'editPermissionRequests.editedByUser:id,name',
        ]);

        return $this->editPermissionRequests
            ->filter(fn (OrderEditPermissionRequest $request): bool => $request->isUsed())
            ->sortBy('edited_at')
            ->values()
            ->all();
    }

    public function canBeApproved(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_REVERTED_TO_MANAGER,
        ], true);
    }

    public function canBeRejected(): bool
    {
        return $this->canBeRejectedByManager() || $this->canBeRejectedByAdmin();
    }

    public function canBeRejectedByManager(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_REVERTED_TO_MANAGER,
        ], true);
    }

    public function canBeRejectedByAdmin(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_ON_HOLD,
            self::STATUS_REVERTED_TO_MANAGER,
            self::STATUS_PENDING_FOR_BILLING,
            self::STATUS_BILLED,
            self::STATUS_DISPATCHED,
        ], true);
    }

    public function canBeHeld(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeReleasedFromHold(): bool
    {
        return $this->status === self::STATUS_ON_HOLD;
    }

    public function canBeRevertedToManager(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeSentForBilling(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeBilled(): bool
    {
        return $this->status === self::STATUS_PENDING_FOR_BILLING;
    }

    public function isAwaitingSendForBill(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeDispatched(): bool
    {
        // Canonical status is enough: billed already means manager approved
        // and Admin completed billing. Extra timestamp checks hid the mobile
        // dispatch action when billed_at/approved_at were missing on otherwise
        // billed orders.
        return $this->status === self::STATUS_BILLED;
    }

    public function canUploadReceivedCopy(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     actor: ?string,
     *     actor_role: ?string,
     *     at: ?string,
     *     status_text: ?string,
     *     remark: ?string,
     *     completed: bool,
     *     is_current: bool,
     *     is_rejection: bool
     * }>
     */
    public function workflowTimeline(): array
    {
        $this->loadMissing([
            'salesEmployee:id,full_name,designation',
            'approvedByUser:id,name,role,job_role,employee_id',
            'approvedByUser.employee:id,full_name,designation',
            'rejectedByUser:id,name,role,job_role,employee_id',
            'rejectedByUser.employee:id,full_name,designation',
            'sentForBillByUser:id,name,role,job_role,employee_id',
            'sentForBillByUser.employee:id,full_name,designation',
            'billedByUser:id,name,role,job_role,employee_id',
            'billedByUser.employee:id,full_name,designation',
            'dispatchedByUser:id,name,role,job_role,employee_id',
            'dispatchedByUser.employee:id,full_name,designation',
            'workflowEvents.user:id,name,role,job_role,employee_id',
            'workflowEvents.user.employee:id,full_name,designation',
        ]);

        $format = fn ($value): ?string => $value === null
            ? null
            : Carbon::parse($value)->timezone(self::BUSINESS_TIMEZONE)->format('d M Y • h:i A');

        $salesRole = filled($this->salesEmployee?->designation)
            ? (string) $this->salesEmployee->designation
            : 'Sales Officer';

        $steps = [[
            'key' => 'created',
            'label' => 'Order Placed',
            'actor' => $this->salesEmployee?->full_name,
            'actor_role' => $salesRole,
            'at' => $format($this->created_at),
            'status_text' => null,
            'remark' => null,
            'completed' => true,
            'is_current' => false,
            'is_rejection' => false,
        ]];

        if ((filled($this->rejected_at) || $this->status === self::STATUS_REJECTED)
            && blank($this->approved_at)) {
            $steps[] = [
                'key' => 'rejected',
                'label' => $this->displayStatusLabel(),
                'actor' => $this->rejectedByUser?->name,
                'actor_role' => $this->rejected_by_role
                    ?: $this->displayActorRole($this->rejectedByUser),
                'at' => $format($this->rejected_at),
                'status_text' => null,
                'remark' => $this->rejection_remark,
                'completed' => true,
                'is_current' => true,
                'is_rejection' => true,
            ];

            return $steps;
        }

        $isOnHold = $this->status === self::STATUS_ON_HOLD;
        $isReverted = $this->status === self::STATUS_REVERTED_TO_MANAGER;
        $isRejected = $this->status === self::STATUS_REJECTED || filled($this->rejected_at);

        $isApproved = filled($this->approved_at)
            || in_array($this->status, [
                self::STATUS_APPROVED,
                self::STATUS_ON_HOLD,
                self::STATUS_REVERTED_TO_MANAGER,
                self::STATUS_PENDING_FOR_BILLING,
                self::STATUS_BILLED,
                self::STATUS_DISPATCHED,
            ], true);

        $isSentForBilling = filled($this->sent_for_bill_at)
            || in_array($this->status, [
                self::STATUS_PENDING_FOR_BILLING,
                self::STATUS_BILLED,
                self::STATUS_DISPATCHED,
            ], true);

        $isBilled = filled($this->billed_at)
            || in_array($this->status, [
                self::STATUS_BILLED,
                self::STATUS_DISPATCHED,
            ], true);

        $isDispatched = filled($this->dispatched_at) || $this->status === self::STATUS_DISPATCHED;

        $steps[] = [
            'key' => 'approved',
            'label' => 'Approved by Sales Manager',
            'actor' => $isApproved ? $this->approvedByUser?->name : null,
            'actor_role' => $isApproved
                ? ($this->displayActorRole($this->approvedByUser) ?? 'Sales Manager')
                : null,
            'at' => $isApproved ? $format($this->approved_at) : null,
            'status_text' => $isApproved ? null : 'Pending',
            'remark' => null,
            'completed' => $isApproved,
            'is_current' => ! $isApproved && ! $isRejected,
            'is_rejection' => false,
        ];

        $latestHeld = $this->workflowEvents->last(
            fn (OrderWorkflowEvent $event): bool => $event->action === OrderWorkflowEvent::ACTION_HELD,
        );
        $latestReverted = $this->workflowEvents->last(
            fn (OrderWorkflowEvent $event): bool => $event->action === OrderWorkflowEvent::ACTION_REVERTED,
        );

        foreach ($this->workflowEvents as $event) {
            if ($event->action === OrderWorkflowEvent::ACTION_DETAILS_CORRECTED) {
                continue;
            }

            $eventIsCurrent = ($isOnHold && $latestHeld !== null && $event->is($latestHeld))
                || ($isReverted && $latestReverted !== null && $event->is($latestReverted));

            $steps[] = [
                'key' => $event->action,
                'label' => $event->timelineLabel(),
                'actor' => $event->user?->name,
                'actor_role' => $this->displayActorRole($event->user)
                    ?? ($event->user_role ?: null),
                'at' => $format($event->created_at),
                'status_text' => $eventIsCurrent ? $this->displayStatusLabel() : null,
                'remark' => $event->remark,
                'completed' => true,
                'is_current' => $eventIsCurrent,
                'is_rejection' => false,
            ];
        }

        if (! $isRejected || $isSentForBilling) {
            $steps[] = [
                'key' => 'pending_for_billing',
                'label' => 'Sent for Bill by Production Supervisor',
                'actor' => $isSentForBilling ? $this->sentForBillByUser?->name : null,
                'actor_role' => $isSentForBilling
                    ? ($this->displayActorRole($this->sentForBillByUser) ?? 'Production Supervisor')
                    : null,
                'at' => $isSentForBilling ? $format($this->sent_for_bill_at) : null,
                'status_text' => $isSentForBilling
                    ? ($isBilled ? null : 'Pending for Billing')
                    : 'Pending',
                'remark' => $isSentForBilling ? $this->sentForBillTimelineRemark() : null,
                'completed' => $isSentForBilling,
                'is_current' => $isApproved && ! $isSentForBilling && ! $isOnHold && ! $isReverted && ! $isRejected,
                'is_rejection' => false,
            ];
        }

        if (! $isRejected || $isBilled) {
            $steps[] = [
                'key' => 'billed',
                'label' => 'Billed by Admin',
                'actor' => $isBilled ? $this->billedByUser?->name : null,
                'actor_role' => $isBilled
                    ? ($this->displayActorRole($this->billedByUser) ?? 'Admin')
                    : null,
                'at' => $isBilled ? $format($this->billed_at) : null,
                'status_text' => $isBilled ? null : 'Pending',
                'remark' => $isBilled ? $this->billing_remark : null,
                'completed' => $isBilled,
                'is_current' => $isSentForBilling && ! $isBilled && ! $isRejected,
                'is_rejection' => false,
            ];
        }

        if (! $isRejected || $isDispatched) {
            $steps[] = [
                'key' => 'dispatched',
                'label' => 'Dispatched by Production Supervisor',
                'actor' => $isDispatched ? $this->dispatchedByUser?->name : null,
                'actor_role' => $isDispatched
                    ? ($this->displayActorRole($this->dispatchedByUser) ?? 'Production Supervisor')
                    : null,
                'at' => $isDispatched ? $format($this->dispatched_at) : null,
                'status_text' => $isDispatched ? null : 'Pending',
                'remark' => $isDispatched ? $this->dispatch_remark : null,
                'completed' => $isDispatched,
                'is_current' => $isBilled && ! $isDispatched && ! $isRejected,
                'is_rejection' => false,
            ];
        }

        foreach ($this->workflowEvents as $event) {
            if ($event->action !== OrderWorkflowEvent::ACTION_DETAILS_CORRECTED) {
                continue;
            }

            $steps[] = [
                'key' => $event->action,
                'label' => $event->timelineLabel(),
                'actor' => $event->user?->name,
                'actor_role' => $this->displayActorRole($event->user)
                    ?? ($event->user_role ?: 'Admin'),
                'at' => $format($event->created_at),
                'status_text' => null,
                'remark' => $event->remark,
                'completed' => true,
                'is_current' => false,
                'is_rejection' => false,
            ];
        }

        if ($isRejected) {
            $steps[] = [
                'key' => 'rejected',
                'label' => $this->displayStatusLabel(),
                'actor' => $this->rejectedByUser?->name,
                'actor_role' => $this->rejected_by_role
                    ?: $this->displayActorRole($this->rejectedByUser),
                'at' => $format($this->rejected_at),
                'status_text' => null,
                'remark' => $this->rejection_remark,
                'completed' => true,
                'is_current' => true,
                'is_rejection' => true,
            ];
        }

        return $steps;
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

    public function billUrl(): ?string
    {
        if (blank($this->bill_path)) {
            return null;
        }

        return url('storage/'.ltrim(str_replace('\\', '/', $this->bill_path), '/'));
    }

    public function receivedCopyUrl(): ?string
    {
        return PublicMediaUrl::fromPublicPath($this->received_copy_path);
    }

    public function storeReceivedCopy(string $path, int $userId): void
    {
        $this->update([
            'received_copy_path' => $path,
            'received_copy_uploaded_by' => $userId,
            'received_copy_uploaded_at' => Carbon::now(self::BUSINESS_TIMEZONE),
        ]);
    }

    public function approve(?int $userId = null, ?string $remark = null): void
    {
        DB::transaction(function () use ($userId, $remark): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === self::STATUS_REVERTED_TO_MANAGER) {
                $locked->reapproveLocked($userId, $remark);
                $this->refresh();

                return;
            }

            if ($locked->status !== self::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or returned orders can be approved.'],
                ]);
            }

            $locked->update([
                'status' => self::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
                'rejected_by' => null,
                'rejected_by_role' => null,
                'rejected_at' => null,
                'rejection_remark' => null,
                'remarks' => filled($remark) ? trim($remark) : $locked->remarks,
            ]);

            $this->refresh();
        });
    }

    public function placeOnHold(User $actor, string $remark): void
    {
        $remark = trim($remark);
        $this->assertRemark($remark, 'hold_remark');

        DB::transaction(function () use ($actor, $remark): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeHeld()) {
                throw ValidationException::withMessages([
                    'status' => ['Only manager-approved orders can be put on hold.'],
                ]);
            }

            $now = Carbon::now(self::BUSINESS_TIMEZONE);
            $locked->update([
                'status' => self::STATUS_ON_HOLD,
                'held_by' => $actor->id,
                'held_at' => $now,
                'hold_remark' => $remark,
                'hold_return_status' => self::STATUS_APPROVED,
                'hold_released_by' => null,
                'hold_released_at' => null,
            ]);
            $locked->recordWorkflowEvent(OrderWorkflowEvent::ACTION_HELD, $actor, $remark, $now);
            $this->refresh();
        });
    }

    public function releaseHold(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeReleasedFromHold()) {
                throw ValidationException::withMessages([
                    'status' => ['Only orders currently on hold can be released.'],
                ]);
            }

            $now = Carbon::now(self::BUSINESS_TIMEZONE);
            $returnStatus = $locked->hold_return_status ?: self::STATUS_APPROVED;
            $locked->update([
                'status' => $returnStatus,
                'hold_released_by' => $actor->id,
                'hold_released_at' => $now,
            ]);
            $locked->recordWorkflowEvent(OrderWorkflowEvent::ACTION_RELEASED, $actor, null, $now);
            $this->refresh();
        });
    }

    public function revertToManager(User $actor, string $remark): void
    {
        $remark = trim($remark);
        $this->assertRemark($remark, 'revert_remark');

        DB::transaction(function () use ($actor, $remark): void {
            /** @var self $locked */
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canBeRevertedToManager()) {
                throw ValidationException::withMessages([
                    'status' => ['Only manager-approved orders can be returned to the manager.'],
                ]);
            }

            $now = Carbon::now(self::BUSINESS_TIMEZONE);
            $locked->update([
                'status' => self::STATUS_REVERTED_TO_MANAGER,
                'reverted_by' => $actor->id,
                'reverted_at' => $now,
                'revert_remark' => $remark,
                'reapproved_by' => null,
                'reapproved_at' => null,
            ]);
            $locked->recordWorkflowEvent(OrderWorkflowEvent::ACTION_REVERTED, $actor, $remark, $now);
            $this->refresh();
        });
    }

    public function reject(?int $userId = null, ?string $remark = null, ?string $rejectedByRole = null): void
    {
        $rejectedByRole = $rejectedByRole ?: self::REJECTED_BY_ROLE_SALES_MANAGER;

        $allowed = match ($rejectedByRole) {
            self::REJECTED_BY_ROLE_ADMIN => $this->canBeRejectedByAdmin(),
            self::REJECTED_BY_ROLE_SALES_MANAGER => $this->canBeRejectedByManager(),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => ['This order cannot be rejected in its current status.'],
            ]);
        }

        if (blank($remark) || mb_strlen(trim($remark)) < 3) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Rejection remarks are required (minimum 3 characters).'],
            ]);
        }

        // Preserve prior workflow history (approval, billing, dispatch, etc.).
        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $userId,
            'rejected_by_role' => $rejectedByRole,
            'rejected_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'rejection_remark' => trim($remark),
        ]);
    }

    public function markAsBilled(
        ?int $userId = null,
        ?string $billPath = null,
        ?string $billNumber = null,
        ?string $remark = null,
        ?string $billDate = null,
    ): void {
        if (! $this->canBeBilled()) {
            throw ValidationException::withMessages([
                'status' => ['Only orders pending for billing can be marked as billed. Waiting for Production Supervisor to Send for Bill.'],
            ]);
        }

        if (blank($billPath)) {
            throw ValidationException::withMessages([
                'bill' => ['Bill document is required.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_BILLED,
            'billed_by' => $userId,
            'billed_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'bill_path' => $billPath,
            'bill_number' => filled($billNumber) ? trim($billNumber) : null,
            'bill_date' => filled($billDate) ? $billDate : Carbon::now(self::BUSINESS_TIMEZONE)->toDateString(),
            'billing_remark' => filled($remark) ? trim($remark) : null,
        ]);
    }

    public function sendForBilling(
        ?int $userId = null,
        ?string $vehicleNumber = null,
        ?float $transportFreight = null,
        ?string $transportRemark = null,
        ?int $vehicleId = null,
        ?string $transportChargeType = null,
    ): void {
        if (! $this->canBeSentForBilling()) {
            throw ValidationException::withMessages([
                'status' => ['Only orders approved by Sales Manager can be sent for billing.'],
            ]);
        }

        if (blank($vehicleNumber)) {
            throw ValidationException::withMessages([
                'vehicle_number' => ['Vehicle number is required.'],
            ]);
        }

        if ($transportFreight === null || $transportFreight < 0) {
            throw ValidationException::withMessages([
                'transport_freight' => ['Transport charges must be a valid non-negative amount.'],
            ]);
        }

        $this->loadMissing('items');

        $billingTransport = OrderBillingTransportCalculator::calculateForOrder(
            $this,
            (string) $transportChargeType,
            $transportFreight,
        );

        $this->update([
            'status' => self::STATUS_PENDING_FOR_BILLING,
            'vehicle_id' => $vehicleId,
            'vehicle_number' => trim($vehicleNumber),
            ...OrderBillingTransportCalculator::persistedAttributes($billingTransport),
            'transport_remark' => filled($transportRemark) ? trim($transportRemark) : null,
            'sent_for_bill_by' => $userId,
            'sent_for_bill_at' => Carbon::now(self::BUSINESS_TIMEZONE),
        ]);
    }

    public function dispatch(?int $userId = null, ?string $remark = null): void
    {
        if (! $this->canBeDispatched()) {
            throw ValidationException::withMessages([
                'status' => ['Only billed orders can be dispatched.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_DISPATCHED,
            'dispatched_by' => $userId,
            'dispatched_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'dispatch_date' => Carbon::now(self::BUSINESS_TIMEZONE)->toDateString(),
            'dispatch_remark' => filled($remark) ? trim($remark) : null,
        ]);
    }

    public function transitionTo(string $status): void
    {
        if (! $this->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => 'This status change is not permitted.']);
        }

        $this->update(['status' => $status]);
    }

    public function recalculateTotals(): void
    {
        OrderBillingTransportCalculator::persistCorrectedTotals($this);
    }

    public function sentForBillTimelineRemark(): ?string
    {
        $parts = [];
        $billing = OrderBillingTransportCalculator::present($this);

        if (filled($this->vehicle_number)) {
            $parts[] = 'Vehicle No: '.$this->vehicle_number;
        }

        if (filled($billing['transport_charge_type_label'])) {
            $parts[] = 'Transport Type: '.$billing['transport_charge_type_label'];
        }

        if ($billing['transport_charges'] !== null) {
            $parts[] = 'Transport Charges: '.OrderBillingTransportCalculator::formatMoney(
                (float) $billing['transport_charges'],
            );
        }

        if (OrderBillingTransportCalculator::hasSavedAdjustment($this)) {
            $parts[] = 'Grand Total: '.OrderBillingTransportCalculator::formatMoney(
                (float) $billing['final_grand_total'],
            );
        }

        if (filled($this->transport_remark)) {
            $parts[] = $this->transport_remark;
        }

        if ($parts === []) {
            return null;
        }

        return implode("\n", $parts);
    }

    private function reapproveLocked(?int $userId, ?string $remark): void
    {
        $now = Carbon::now(self::BUSINESS_TIMEZONE);

        $this->update([
            'status' => self::STATUS_APPROVED,
            'reapproved_by' => $userId,
            'reapproved_at' => $now,
            'rejected_by' => null,
            'rejected_by_role' => null,
            'rejected_at' => null,
            'rejection_remark' => null,
            'remarks' => filled($remark) ? trim($remark) : $this->remarks,
        ]);

        if ($userId) {
            $actor = User::query()->find($userId);
            if ($actor !== null) {
                $this->recordWorkflowEvent(
                    OrderWorkflowEvent::ACTION_REAPPROVED,
                    $actor,
                    filled($remark) ? trim($remark) : null,
                    $now,
                );
            }
        }
    }

    public function recordDetailsCorrected(User $editor, OrderEditPermissionRequest $request): void
    {
        $now = Carbon::now(self::BUSINESS_TIMEZONE);

        $this->recordWorkflowEvent(
            OrderWorkflowEvent::ACTION_DETAILS_CORRECTED,
            $editor,
            $request->workflowRemark(),
            $now,
        );
    }

    private function recordWorkflowEvent(string $action, User $actor, ?string $remark, Carbon $at): void
    {
        $this->workflowEvents()->create([
            'action' => $action,
            'user_id' => $actor->id,
            'user_role' => $this->displayActorRole($actor) ?? $actor->roleEnum()->label(),
            'remark' => $remark,
            'created_at' => $at,
        ]);
    }

    private function assertRemark(string $remark, string $field): void
    {
        if (blank($remark) || mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                $field => ['Remark is required (minimum 3 characters).'],
            ]);
        }
    }
}
