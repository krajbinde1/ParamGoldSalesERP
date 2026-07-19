<?php

namespace App\Filament\Concerns;

trait DeniesOrdersOnlyFilamentUsers
{
    public static function canAccess(): bool
    {
        if (auth()->user()?->hasOrdersOnlyFilamentAccess() ?? false) {
            return false;
        }

        return parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->user()?->hasOrdersOnlyFilamentAccess() ?? false) {
            return false;
        }

        return parent::shouldRegisterNavigation();
    }
}
