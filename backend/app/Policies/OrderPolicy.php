<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ManagerOrderAccessService;
use Filament\Facades\Filament;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        if ($this->filamentProductionOrderUser($user) || $user->isAdminUser() || $user->isDirectorUser()) {
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
        if ($this->filamentProductionOrderUser($user) || $user->isAdminUser() || $user->isDirectorUser()) {
            return true;
        }

        if ($user->canActAsProductionSupervisor()) {
            return in_array($order->status, [
                Order::STATUS_APPROVED,
                Order::STATUS_ON_HOLD,
                Order::STATUS_REVERTED_TO_MANAGER,
                Order::STATUS_PENDING_FOR_BILLING,
                Order::STATUS_BILLED,
                Order::STATUS_DISPATCHED,
                Order::STATUS_REJECTED,
            ], true);
        }

        return match ($user->roleEnum()) {
            UserRole::Employee => $order->sales_employee_id === $user->employee_id,
            UserRole::Manager => $this->managerOwnsOrder($user, $order),
            UserRole::Director => true,
            UserRole::ProductionSupervisor => in_array($order->status, [
                Order::STATUS_APPROVED,
                Order::STATUS_ON_HOLD,
                Order::STATUS_REVERTED_TO_MANAGER,
                Order::STATUS_PENDING_FOR_BILLING,
                Order::STATUS_BILLED,
                Order::STATUS_DISPATCHED,
                Order::STATUS_REJECTED,
            ], true),
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

        if ($user->hasRole(UserRole::Manager)) {
            return $order->canBeEdited() && $this->managerOwnsOrder($user, $order);
        }

        return $user->hasRole(UserRole::Employee)
            && $order->sales_employee_id === $user->employee_id
            && $order->canBeEdited();
    }

    public function approve(User $user, Order $order): bool
    {
        if ($user->canActAsProductionSupervisor() || $user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        if ($this->filamentProductionManager($user)) {
            return $order->canBeApproved();
        }

        return $user->hasRole(UserRole::Manager)
            && $order->canBeApproved()
            && $this->managerOwnsOrder($user, $order);
    }

    public function reject(User $user, Order $order): bool
    {
        if ($user->canActAsProductionSupervisor()) {
            return false;
        }

        if ($user->isAdminUser()) {
            return $order->canBeRejectedByAdmin();
        }

        if ($user->isDirectorUser()) {
            return false;
        }

        if ($this->filamentProductionManager($user)) {
            return $order->canBeRejectedByManager();
        }

        return $user->hasRole(UserRole::Manager)
            && $order->canBeRejectedByManager()
            && $this->managerOwnsOrder($user, $order);
    }

    public function bill(User $user, Order $order): bool
    {
        if (! $order->canBeBilled()) {
            return false;
        }

        // Admin (Filament job role) only — Director remains view-only.
        return $user->isAdminUser();
    }

    public function sendForBill(User $user, Order $order): bool
    {
        if (! $order->canBeSentForBilling()) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return $user->hasRole(UserRole::ProductionSupervisor);
    }

    public function hold(User $user, Order $order): bool
    {
        if (! $order->canBeHeld()) {
            return false;
        }

        return $this->isProductionActor($user);
    }

    public function releaseHold(User $user, Order $order): bool
    {
        if (! $order->canBeReleasedFromHold()) {
            return false;
        }

        return $this->isProductionActor($user);
    }

    public function revertToManager(User $user, Order $order): bool
    {
        if (! $order->canBeRevertedToManager()) {
            return false;
        }

        return $this->isProductionActor($user);
    }

    private function isProductionActor(User $user): bool
    {
        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return $user->hasRole(UserRole::ProductionSupervisor);
    }

    public function dispatch(User $user, Order $order): bool
    {
        if (! $order->canBeDispatched()) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return $user->hasRole(UserRole::ProductionSupervisor);
    }

    public function uploadReceivedCopy(User $user, Order $order): bool
    {
        if (! $order->canUploadReceivedCopy()) {
            return false;
        }

        if ($user->isAdminUser() || $user->isDirectorUser()) {
            return false;
        }

        if ($user->canActAsProductionSupervisor()) {
            return true;
        }

        return $user->hasRole(UserRole::ProductionSupervisor);
    }

    private function managerOwnsOrder(User $user, Order $order): bool
    {
        return app(ManagerOrderAccessService::class)->managerCanAccessOrder($user, $order);
    }

    private function filamentProductionOrderUser(User $user): bool
    {
        if (! Filament::auth()->check()) {
            return false;
        }

        return $user->hasOrdersOnlyFilamentAccess()
            || $user->canActAsProductionSupervisor()
            || $user->isAdminUser()
            || $user->isDirectorUser();
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
