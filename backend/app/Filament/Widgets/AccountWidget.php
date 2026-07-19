<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget as BaseAccountWidget;

class AccountWidget extends BaseAccountWidget
{
    protected string $view = 'filament.widgets.account-widget';

    public static function canView(): bool
    {
        return ! auth()->user()?->hasOrdersOnlyFilamentAccess()
            && ! auth()->user()?->usesManagerDashboard()
            && ! auth()->user()?->usesAdminDirectorDashboard();
    }

    public function getRoleLabel(): string
    {
        $user = Filament::auth()->user();

        if ($user === null) {
            return '-';
        }

        if (filled($user->job_role)) {
            return $user->job_role;
        }

        if (filled($user->employee?->designation)) {
            return $user->employee->designation;
        }

        if (blank($user->role)) {
            return '-';
        }

        return UserRole::tryFrom($user->role)?->label() ?? '-';
    }
}
