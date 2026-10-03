<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerCreditLimit extends Model
{
    protected $fillable = [
        'dealer_id',
        'base_limit',
        'extension_amount',
        'extension_valid_until',
        'extension_expired_at',
        'extension_remark',
        'remark',
        'set_by_user_id',
        'set_by_role',
    ];

    protected function casts(): array
    {
        return [
            'base_limit' => 'decimal:2',
            'extension_amount' => 'decimal:2',
            'extension_valid_until' => 'date',
            'extension_expired_at' => 'datetime',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
