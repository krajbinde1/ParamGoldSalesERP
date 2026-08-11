<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_BILLED = 'billed';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_REJECTED = 'rejected';

    public const REJECTED_BY_ROLE_SALES_MANAGER = 'Sales Manager';

    public const REJECTED_BY_ROLE_ADMIN = 'Admin';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Sales Manager Approval',
        'approved' => 'Approved by Sales Manager',
        'billed' => 'Billed by Admin',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    private const STATUS_TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['billed', 'rejected'],
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
        'billed_by', 'billed_at', 'bill_path', 'bill_number', 'billing_remark',
        'dispatched_by', 'dispatched_at', 'dispatch_date', 'dispatch_remark',
        'transport_type', 'transport_amount', 'transporter_name', 'vehicle_number', 'lr_number', 'lr_document_path',
        'subtotal_before_transport', 'taxable_amount_after_transport',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'dispatch_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'transport_amount' => 'decimal:2',
            'subtotal_before_transport' => 'decimal:2',
            'taxable_amount_after_transport' => 'decimal:2',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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

    public function billedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_by');
    }

    public function dispatchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
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
            'pending_approval' => 'warning',
            'approved' => 'success',
            'billed' => 'warning',
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
        return $this->status === 'pending_approval';
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function canBeRejected(): bool
    {
        return $this->canBeRejectedByManager() || $this->canBeRejectedByAdmin();
    }

    public function canBeRejectedByManager(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function canBeRejectedByAdmin(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
        ], true);
    }

    public function canBeBilled(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeDispatched(): bool
    {
        return $this->status === self::STATUS_BILLED;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     actor: ?string,
     *     at: ?string,
     *     remark: ?string,
     *     completed: bool,
     *     is_rejection: bool
     * }>
     */
    public function workflowTimeline(): array
    {
        $this->loadMissing([
            'salesEmployee:id,full_name',
            'approvedByUser:id,name',
            'rejectedByUser:id,name',
            'billedByUser:id,name',
            'dispatchedByUser:id,name',
        ]);

        $format = fn ($value): ?string => $value === null
            ? null
            : Carbon::parse($value)->timezone(self::BUSINESS_TIMEZONE)->format('d M Y, h:i A');

        $steps = [[
            'key' => 'created',
            'label' => 'Order Created',
            'actor' => $this->salesEmployee?->full_name,
            'at' => $format($this->created_at),
            'remark' => null,
            'completed' => true,
            'is_rejection' => false,
        ]];

        if (filled($this->approved_at)) {
            $steps[] = [
                'key' => 'approved',
                'label' => 'Approved by Sales Manager',
                'actor' => $this->approvedByUser?->name,
                'at' => $format($this->approved_at),
                'remark' => null,
                'completed' => true,
                'is_rejection' => false,
            ];
        } elseif ($this->status === self::STATUS_PENDING_APPROVAL) {
            $steps[] = [
                'key' => 'pending_approval',
                'label' => 'Pending Sales Manager Approval',
                'actor' => null,
                'at' => null,
                'remark' => null,
                'completed' => false,
                'is_rejection' => false,
            ];
        }

        if (filled($this->rejected_at) || $this->status === self::STATUS_REJECTED) {
            $steps[] = [
                'key' => 'rejected',
                'label' => $this->displayStatusLabel(),
                'actor' => $this->rejectedByUser?->name,
                'at' => $format($this->rejected_at),
                'remark' => $this->rejection_remark,
                'completed' => true,
                'is_rejection' => true,
            ];

            return $steps;
        }

        if (filled($this->billed_at) || $this->status === self::STATUS_BILLED || $this->status === self::STATUS_DISPATCHED) {
            $steps[] = [
                'key' => 'billed',
                'label' => 'Billed by Admin',
                'actor' => $this->billedByUser?->name,
                'at' => $format($this->billed_at),
                'remark' => $this->billing_remark,
                'completed' => filled($this->billed_at),
                'is_rejection' => false,
            ];
        }

        if (filled($this->dispatched_at) || $this->status === self::STATUS_DISPATCHED) {
            $steps[] = [
                'key' => 'dispatched',
                'label' => 'Dispatched',
                'actor' => $this->dispatchedByUser?->name,
                'at' => $format($this->dispatched_at),
                'remark' => $this->dispatch_remark,
                'completed' => filled($this->dispatched_at),
                'is_rejection' => false,
            ];
        } elseif ($this->status === self::STATUS_BILLED) {
            $steps[] = [
                'key' => 'awaiting_dispatch',
                'label' => 'Awaiting Dispatch',
                'actor' => null,
                'at' => null,
                'remark' => null,
                'completed' => false,
                'is_rejection' => false,
            ];
        } elseif ($this->status === self::STATUS_APPROVED) {
            $steps[] = [
                'key' => 'awaiting_billing',
                'label' => 'Awaiting Billing by Admin',
                'actor' => null,
                'at' => null,
                'remark' => null,
                'completed' => false,
                'is_rejection' => false,
            ];
        }

        return $steps;
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
    ): void {
        if (! $this->canBeBilled()) {
            throw ValidationException::withMessages([
                'status' => ['Only approved orders can be marked as billed.'],
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
            'billing_remark' => filled($remark) ? trim($remark) : null,
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

        $this->forceFill([
            'subtotal' => round($totals['subtotal'], 2),
            'discount_amount' => round($totals['discount_amount'], 2),
            'gst_amount' => round($totals['gst_amount'], 2),
            'grand_total' => round($totals['grand_total'], 2),
        ])->saveQuietly();
    }
}
