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
        'apk_file_size',
        'apk_sha256',
        'update_message',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'latest_build' => 'integer',
            'force_update' => 'boolean',
            'apk_file_size' => 'integer',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
