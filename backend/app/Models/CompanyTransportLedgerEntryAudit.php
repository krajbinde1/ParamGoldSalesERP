<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyTransportLedgerEntryAudit extends Model
{
    protected $fillable = [
        'entry_id',
        'action',
        'old_values',
        'new_values',
        'actor_id',
        'actor_role',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'acted_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CompanyTransportLedgerEntry::class, 'entry_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
