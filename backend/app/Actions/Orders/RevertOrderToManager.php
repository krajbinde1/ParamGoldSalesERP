<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RevertOrderToManager
{
    /**
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor, string $remark): array
    {
        if (! Gate::forUser($actor)->allows('revertToManager', $order)) {
            throw new AuthorizationException('You are not allowed to return this order to the manager.');
        }

        if (! $order->canBeRevertedToManager()) {
            throw ValidationException::withMessages([
                'status' => ['Only manager-approved orders can be returned to the manager.'],
            ]);
        }

        $order->revertToManager($actor, $remark);

        return ['order' => $order->fresh() ?? $order];
    }
}
