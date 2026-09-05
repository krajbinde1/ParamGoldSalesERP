<?php

namespace App\Policies;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $user->canAccessInventoryModule();
    }

    public function create(User $user): bool
    {
        return $user->canCreatePurchase();
    }

    public function update(User $user, Purchase $purchase): bool
    {
        if ($purchase->status === PurchaseStatus::Cancelled) {
            return false;
        }

        if ($purchase->isDraft()) {
            return $user->canCreatePurchase();
        }

        return $user->canUpdatePurchase() && $purchase->canSafelyEditConfirmed();
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }

    public function confirm(User $user, Purchase $purchase): bool
    {
        return $user->canCreatePurchase() && $purchase->status->canConfirm();
    }

    public function cancel(User $user, Purchase $purchase): bool
    {
        return $user->canCancelPurchase() && $purchase->status->canCancel();
    }
}
