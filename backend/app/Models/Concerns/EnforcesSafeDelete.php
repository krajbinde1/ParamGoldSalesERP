<?php

namespace App\Models\Concerns;

use App\Services\SafeDelete\SafeDeleteGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Blocks permanent deletion when the master record has transactional/history dependencies.
 */
trait EnforcesSafeDelete
{
    public static function bootEnforcesSafeDelete(): void
    {
        static::deleting(function (Model $model): void {
            // SoftDeletes fires "deleting" for soft delete as well — both must be protected.
            app(SafeDeleteGuard::class)->assertCanDelete($model);
        });
    }

    public function safeDeleteAssessment(): \App\Services\SafeDelete\SafeDeleteAssessment
    {
        return app(SafeDeleteGuard::class)->assess($this);
    }

    public function canBeSafelyDeleted(): bool
    {
        return app(SafeDeleteGuard::class)->canDelete($this);
    }
}
