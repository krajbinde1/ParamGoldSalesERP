<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerCreditLimitAudit extends Model
{
    public const ACTION_SET = 'set';

    public const ACTION_EDIT = 'edit';

    public const ACTION_EXTEND = 'extend';

    public const ACTION_EXPIRE = 'expire';

    protected $fillable = [
        'dealer_id',
        'action',
        'previous_base',
        'new_base',
        'extension_amount',
        'effective_limit',
        'valid_until',
        'remark',
        'changed_by_user_id',
        'changed_by_role',
    ];

    protected function casts(): array
    {
        return [
            'previous_base' => 'decimal:2',
            'new_base' => 'decimal:2',
            'extension_amount' => 'decimal:2',
            'effective_limit' => 'decimal:2',
            'valid_until' => 'date',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
