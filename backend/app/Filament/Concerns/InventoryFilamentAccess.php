<?php

namespace App\Filament\Concerns;

use App\Enums\UserRole;

trait InventoryFilamentAccess
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Shared across every inventory resource/page in the same request (nav build).
        $cacheKey = 'filament.inventory_access.'.(int) $user->getKey();

        if (app()->bound($cacheKey)) {
            return (bool) app($cacheKey);
        }

        // Role helpers may resolve job_role from the linked employee.
        $user->loadMissing('employee');

        $allowed = true;

        // Employees without any elevated inventory role are denied.
        if (
            $user->hasRole(UserRole::Employee)
            && ! $user->isAdminUser()
            && ! $user->isDirectorUser()
            && ! $user->canActAsProductionSupervisor()
            && ! $user->isManagerUser()
        ) {
            $allowed = false;
        } elseif ($user->isProductionManagerOnlyInFilament()) {
            // Production manager only (orders-only, without supervisor capability) is denied.
            $allowed = false;
        } else {
            // Allow: admin/director, production supervisor, manager (view access).
            $allowed = $user->canAccessInventoryModule();
        }

        app()->instance($cacheKey, $allowed);

        return $allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
