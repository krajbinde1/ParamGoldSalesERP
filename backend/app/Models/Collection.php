<?php

namespace App\Models;

use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Collection extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_NOT_RECEIVED = 'not_received';

    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private const STATUS_TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_RECEIVED,
            self::STATUS_NOT_RECEIVED,
        ],
        self::STATUS_RECEIVED => [],
        self::STATUS_NOT_RECEIVED => [],
    ];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_NOT_RECEIVED => 'Not Received',
    ];

    private ?array $verifiedSnapshot = null;

    protected static function booted(): void
    {
        static::saving(function (Collection $collection): void {
            $collection->verifiedSnapshot = $collection->exists ? [
                'dealer_id' => $collection->getOriginal('dealer_id'),
                'amount' => (float) $collection->getOriginal('amount'),
                'status' => $collection->getOriginal('status'),
            ] : null;
        });

        static::saved(function (Collection $collection): void {
            if (($collection->verifiedSnapshot['status'] ?? null) === self::STATUS_RECEIVED) {
                static::adjustOutstanding($collection->verifiedSnapshot['dealer_id'], $collection->verifiedSnapshot['amount']);
            }

            if ($collection->status === self::STATUS_RECEIVED) {
                static::adjustOutstanding($collection->dealer_id, -(float) $collection->amount);
            }
        });

        static::deleted(function (Collection $collection): void {
            if (! $collection->isForceDeleting() && $collection->status === self::STATUS_RECEIVED) {
                static::adjustOutstanding($collection->dealer_id, (float) $collection->amount);
            }
        });

        static::restored(function (Collection $collection): void {
            if ($collection->status === self::STATUS_RECEIVED) {
                static::adjustOutstanding($collection->dealer_id, -(float) $collection->amount);
            }
        });
    }

    protected $fillable = [
        'collection_date',
        'dealer_id',
        'sales_employee_id',
        'amount',
        'remarks',
        'photo_path',
        'admin_remark',
        'status',
        'receipt_no',
        'payment_mode',
        'bank_name',
        'transaction_number',
    ];

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public static function businessToday(): Carbon
    {
        return Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay();
    }

    public static function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_RECEIVED => 'success',
            self::STATUS_NOT_RECEIVED => 'danger',
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

    public function referenceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reference_order_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(CollectionAudit::class)->orderByDesc('id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->getOriginal('status') ?? $this->status] ?? [], true);
    }

    public function canBeEdited(): bool
    {
        return false;
    }

    public function transitionTo(string $status, array $attributes = []): void
    {
        if (! $this->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => 'This status change is not permitted.',
            ]);
        }

        if ($status === self::STATUS_NOT_RECEIVED && blank($attributes['admin_remark'] ?? null)) {
            throw ValidationException::withMessages([
                'admin_remark' => 'Admin remark is required when marking a collection as not received.',
            ]);
        }

        $this->update(array_merge(['status' => $status], $attributes));
    }

    public function photoUrl(): ?string
    {
        return PublicMediaUrl::fromPublicPath($this->photo_path);
    }

    private static function adjustOutstanding(?int $dealerId, float $amount): void
    {
        if ($dealerId !== null) {
            Dealer::withTrashed()->whereKey($dealerId)->increment('outstanding', $amount);
        }
    }
}
