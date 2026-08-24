<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderWorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_HELD = 'held';

    public const ACTION_RELEASED = 'released';

    public const ACTION_REVERTED = 'reverted';

    public const ACTION_REAPPROVED = 'reapproved';

    public const ACTION_DETAILS_CORRECTED = 'details_corrected';

    protected $fillable = [
        'order_id',
        'action',
        'user_id',
        'user_role',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timelineLabel(): string
    {
        return match ($this->action) {
            self::ACTION_HELD => 'Order On Hold by Production Supervisor',
            self::ACTION_RELEASED => 'Hold Released by Production Supervisor',
            self::ACTION_REVERTED => 'Reverted to Manager',
            self::ACTION_REAPPROVED => 'Re-Approved by Sales Manager',
            self::ACTION_DETAILS_CORRECTED => 'Order Details Corrected by Admin',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }
}
