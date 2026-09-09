<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentFollowUpCycle extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'dealer_id',
        'employee_id',
        'cycle_number',
        'opening_outstanding',
        'started_at',
        'status',
        'closed_at',
        'payment_received_amount',
        'closing_outstanding',
        'collection_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'opening_outstanding' => 'decimal:2',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'payment_received_amount' => 'decimal:2',
            'closing_outstanding' => 'decimal:2',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PaymentFollowUpEntry::class, 'cycle_id')->orderBy('id');
    }

    public function latestEntry(): HasOne
    {
        return $this->hasOne(PaymentFollowUpEntry::class, 'cycle_id')->latestOfMany('id');
    }

    public function latestFollowUpEntry(): HasOne
    {
        return $this->hasOne(PaymentFollowUpEntry::class, 'cycle_id')
            ->ofMany(['id' => 'max'], function ($query): void {
                $query->where('entry_type', PaymentFollowUpEntry::TYPE_FOLLOW_UP);
            });
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
