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

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'billed' => 'Billed',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    private const STATUS_TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['billed'],
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
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_remark',
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
        return $this->status === 'pending_approval';
    }

    public function canBeBilled(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeDispatched(): bool
    {
        return $this->status === self::STATUS_BILLED;
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
            'rejected_at' => null,
            'rejection_remark' => null,
            'remarks' => filled($remark) ? trim($remark) : $this->remarks,
        ]);
    }

    public function reject(?int $userId = null, ?string $remark = null): void
    {
        if (! $this->canBeRejected()) {
            throw ValidationException::withMessages([
                'status' => ['Only pending orders can be rejected.'],
            ]);
        }

        if (blank($remark)) {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remark is required.'],
            ]);
        }

        $this->update([
            'status' => 'rejected',
            'rejected_by' => $userId,
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
