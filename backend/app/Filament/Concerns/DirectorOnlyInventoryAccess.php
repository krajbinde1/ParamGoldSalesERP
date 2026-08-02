<?php

namespace App\Filament\Concerns;

trait DirectorOnlyInventoryAccess
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->usesAdminDirectorDashboard() || $user->isAdminUser();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
