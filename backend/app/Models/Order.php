<?php

namespace App\Models;

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

    private const STATUS_TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        // Billing requires Production Supervisor "Send for Bill" first.
        'approved' => ['pending_for_billing', 'rejected', 'on_hold', 'reverted_to_manager'],
        'on_hold' => ['approved', 'rejected'],
        'reverted_to_manager' => ['approved', 'rejected'],
        'pending_for_billing' => ['billed', 'rejected'],
        'billed' => ['dispatched'],
        'dispatched' => [],
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
            'is_current' => false,
            'is_rejection' => false,
        ];

        foreach ($this->workflowEvents as $event) {
            $eventIsCurrent = ($isOnHold && $event->action === OrderWorkflowEvent::ACTION_HELD && $event->is($this->workflowEvents->last()))
                || ($isReverted && $event->action === OrderWorkflowEvent::ACTION_REVERTED && $event->is($this->workflowEvents->last()));

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
            'is_current' => $isApproved && ! $isSentForBilling,
            'is_rejection' => false,
        ];

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
            'is_current' => $isSentForBilling && ! $isBilled,
            'is_rejection' => false,
        ];

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
            'is_current' => $isBilled && ! $isDispatched,
            'is_rejection' => false,
        ];

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

    public function approve(?int $userId = null, ?string $remark = null): void
    {
        if (! $this->canBeApproved()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending orders can be approved.'],
            ]);
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => Carbon::now(self::BUSINESS_TIMEZONE),
            'rejected_by' => null,
            'rejected_by_role' => null,
            'rejected_at' => null,
            'rejection_remark' => null,
            'remarks' => filled($remark) ? trim($remark) : $this->remarks,
        ]);
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

        // Preserve prior approval history when Admin rejects an already-approved order.
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

        $billingTransport = \App\Services\Orders\OrderBillingTransportCalculator::calculate(
            originalGrandTotal: \App\Services\Orders\OrderBillingTransportCalculator::originalGrandTotal($this),
            chargeType: (string) $transportChargeType,
            transportCharges: $transportFreight,
        );

        $this->update([
            'status' => self::STATUS_PENDING_FOR_BILLING,
            'vehicle_id' => $vehicleId,
            'vehicle_number' => trim($vehicleNumber),
            'transport_charge_type' => $billingTransport['transport_charge_type'],
            'transport_amount' => $billingTransport['transport_amount'],
            'original_grand_total' => $billingTransport['original_grand_total'],
            'transport_adjustment' => $billingTransport['transport_adjustment'],
            'grand_total' => $billingTransport['final_grand_total'],
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
        $totals = $this->items()->get()->reduce(function (array $totals, OrderItem $item): array {
            $amounts = app(\App\Services\Orders\OrderLineCalculationService::class)->resolveStoredAmounts($item);

            $totals['subtotal'] += $amounts['base_amount'];
            $totals['discount_amount'] += $amounts['discount_amount'];
            $totals['gst_amount'] += $amounts['gst_amount'];
            $totals['grand_total'] += $amounts['final_amount'];
            $totals['total_cases'] += (int) ($item->case_quantity ?? 1);
            $totals['total_quantity_nos'] += (int) ($item->total_quantity_nos ?? round((float) $item->quantity));

            return $totals;
        }, [
            'subtotal' => 0,
            'discount_amount' => 0,
            'gst_amount' => 0,
            'grand_total' => 0,
            'total_cases' => 0,
            'total_quantity_nos' => 0,
        ]);

        $itemGrandTotal = round($totals['grand_total'], 2);
        $grandTotal = $itemGrandTotal;

        if ($this->original_grand_total !== null && $this->transport_adjustment !== null) {
            $grandTotal = round((float) $this->original_grand_total + (float) $this->transport_adjustment, 2);
        }

        $this->forceFill([
            'subtotal' => round($totals['subtotal'], 2),
            'discount_amount' => round($totals['discount_amount'], 2),
            'gst_amount' => round($totals['gst_amount'], 2),
            'grand_total' => $grandTotal,
        ])->saveQuietly();
    }

    public function sentForBillTimelineRemark(): ?string
    {
        $parts = [];
        $billing = \App\Services\Orders\OrderBillingTransportCalculator::present($this);

        if (filled($this->vehicle_number)) {
            $parts[] = 'Vehicle No: '.$this->vehicle_number;
        }

        if (filled($billing['transport_charge_type_label'])) {
            $parts[] = 'Transport Type: '.$billing['transport_charge_type_label'];
        }

        if ($billing['transport_charges'] !== null) {
            $parts[] = 'Transport Charges: '.\App\Services\Orders\OrderBillingTransportCalculator::formatMoney(
                (float) $billing['transport_charges'],
            );
        }

        if (\App\Services\Orders\OrderBillingTransportCalculator::hasSavedAdjustment($this)) {
            $parts[] = 'Final Grand Total: '.\App\Services\Orders\OrderBillingTransportCalculator::formatMoney(
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
}
