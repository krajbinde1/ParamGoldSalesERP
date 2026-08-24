<?php

namespace App\Models;

use App\Enums\TransportChargeType;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class OrderEditPermissionRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_USED = 'used';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending Director Approval',
        self::STATUS_APPROVED => 'Approved — awaiting Admin correction',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_USED => 'Used',
    ];

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    protected $fillable = [
        'order_id',
        'requested_by',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_remark',
        'edited_by',
        'edited_at',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'edited_at' => 'datetime',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function editedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApprovedUnused(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
        ]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApprovedUnused(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isUsed(): bool
    {
        return $this->status === self::STATUS_USED;
    }

    public function displayStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'info',
            self::STATUS_USED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public function formattedReviewedAt(): ?string
    {
        return $this->formatBusinessDateTime($this->reviewed_at);
    }

    public function formattedEditedAt(): ?string
    {
        return $this->formatBusinessDateTime($this->edited_at);
    }

    public function formattedRequestedAt(): ?string
    {
        return $this->formatBusinessDateTime($this->created_at);
    }

    /**
     * @return list<array{field: string, label: string, old: string, new: string}>
     */
    public function auditRows(): array
    {
        $old = is_array($this->old_values) ? $this->old_values : [];
        $new = is_array($this->new_values) ? $this->new_values : [];
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $rows = [];

        foreach ($keys as $key) {
            $label = $this->fieldLabel((string) $key);
            if ($label === null) {
                continue;
            }

            $rows[] = [
                'field' => (string) $key,
                'label' => $label,
                'old' => $this->formatFieldValue((string) $key, $old[$key] ?? null),
                'new' => $this->formatFieldValue((string) $key, $new[$key] ?? null),
            ];
        }

        return $rows;
    }

    public function workflowRemark(): string
    {
        $director = $this->reviewedByUser?->name ?: 'Director';
        $admin = $this->editedByUser?->name ?: ($this->requestedByUser?->name ?: 'Admin');
        $reason = trim((string) $this->reason);

        return implode("\n", [
            'Approved by Director: '.$director,
            'Edited by Admin: '.$admin,
            'Reason: '.$reason,
        ]);
    }

    private function fieldLabel(string $key): ?string
    {
        return match ($key) {
            'vehicle_number' => 'Vehicle No.',
            'transport_charge_type' => 'Transport Type',
            'transport_amount' => 'Transport Charges',
            'grand_total' => 'Final Grand Total',
            default => null,
        };
    }

    private function formatFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'transport_charge_type' => TransportChargeType::tryFrom((string) $value)?->label() ?: (string) $value,
            'transport_amount', 'grand_total' => OrderBillingTransportCalculator::formatMoney((float) $value),
            default => (string) $value,
        };
    }

    private function formatBusinessDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->timezone(self::BUSINESS_TIMEZONE)->format('d M Y • h:i A');
    }
}
