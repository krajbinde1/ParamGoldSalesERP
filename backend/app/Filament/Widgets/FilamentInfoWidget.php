<?php

namespace App\Filament\Widgets;

use Filament\Widgets\FilamentInfoWidget as BaseFilamentInfoWidget;

class FilamentInfoWidget extends BaseFilamentInfoWidget
{
    public static function canView(): bool
    {
        return ! auth()->user()?->hasOrdersOnlyFilamentAccess()
            && ! auth()->user()?->usesManagerDashboard()
            && ! auth()->user()?->usesAdminDirectorDashboard();
    }
}
