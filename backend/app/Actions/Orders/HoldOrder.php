<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class HoldOrder
{
    /**
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor, string $remark): array
    {
        if (! Gate::forUser($actor)->allows('hold', $order)) {
            throw new AuthorizationException('You are not allowed to put this order on hold.');
        }

        if (! $order->canBeHeld()) {
            throw ValidationException::withMessages([
                'status' => ['Only manager-approved orders can be put on hold.'],
            ]);
        }

        $order->placeOnHold($actor, $remark);

        return ['order' => $order->fresh() ?? $order];
    }
}
