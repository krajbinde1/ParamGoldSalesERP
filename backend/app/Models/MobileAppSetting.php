<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppSetting extends Model
{
    protected $fillable = [
        'latest_version',
        'latest_build',
        'force_update',
        'apk_url',
        'update_message',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'latest_build' => 'integer',
            'force_update' => 'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
