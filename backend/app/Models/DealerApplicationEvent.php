<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealerApplicationEvent extends Model
{
    public const CREATED = 'created';

    public const SUBMITTED = 'submitted';

    public const MANAGER_APPROVED = 'manager_approved';

    public const ADMIN_APPROVED = 'admin_approved';

    public const REJECTED = 'rejected';

    public const SENT_BACK = 'sent_back';

    public const DEALER_CODE_GENERATED = 'dealer_code_generated';

    public const PARTY_CREATED = 'party_created';

    public const RESUBMITTED = 'resubmitted';

    protected $fillable = [
        'dealer_application_id',
        'event_type',
        'actor_user_id',
        'actor_name',
        'remark',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(DealerApplication::class, 'dealer_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
