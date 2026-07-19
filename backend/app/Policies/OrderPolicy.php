<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ($this->filamentProductionOrderUser($user)) {
            return true;
        }

        return $user->hasAnyRole([
            UserRole::Employee,
            UserRole::Manager,
            UserRole::Director,
            UserRole::ProductionSupervisor,
        ]);
    }

    public function view(User $user, Order $order): bool
    {
        if ($this->filamentProductionOrderUser($user)) {
            return true;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return match ($user->roleEnum()) {
            UserRole::Employee => $order->sales_employee_id === $user->employee_id,
            UserRole::Manager, UserRole::Director => true,
            UserRole::ProductionSupervisor => in_array($order->status, ['approved', 'dispatched'], true),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        if ($this->filamentProductionOrderUser($user)) {
            return false;
        }

        return $user->hasRole(UserRole::Employee);
    }

    public function update(User $user, Order $order): bool
    {
        if ($this->filamentProductionOrderUser($user)) {
            return false;
        }

        return $user->hasRole(UserRole::Employee)
            && $order->sales_employee_id === $user->employee_id
            && $order->canBeEdited();
    }

    public function approve(User $user, Order $order): bool
    {
        if ($user->canActAsProductionSupervisor()) {
            return false;
        }

        if ($this->filamentProductionManager($user)) {
            return $order->canBeApproved();
        }

        return $user->hasRole(UserRole::Manager) && $order->canBeApproved();
    }

    public function reject(User $user, Order $order): bool
    {
        if ($user->canActAsProductionSupervisor()) {
            return false;
        }

        if ($this->filamentProductionManager($user)) {
            return $order->canBeRejected();
        }

        return $user->hasRole(UserRole::Manager) && $order->canBeRejected();
    }

    public function dispatch(User $user, Order $order): bool
    {
        if (! $order->canBeDispatched()) {
            return false;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return $user->hasRole(UserRole::ProductionSupervisor);
    }

    private function filamentProductionOrderUser(User $user): bool
    {
        if (! Filament::auth()->check()) {
            return false;
        }

        return $user->hasOrdersOnlyFilamentAccess()
            || $user->canActAsProductionSupervisor();
    }

    private function filamentProductionManager(User $user): bool
    {
        if (! Filament::auth()->check()) {
            return false;
        }

        return $user->hasProductionManagerJobRole()
            && ! $user->canActAsProductionSupervisor();
    }
}
