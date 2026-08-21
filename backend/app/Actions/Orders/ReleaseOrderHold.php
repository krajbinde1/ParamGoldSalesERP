<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReleaseOrderHold
{
    /**
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor): array
    {
        if (! Gate::forUser($actor)->allows('releaseHold', $order)) {
            throw new AuthorizationException('You are not allowed to release this hold.');
        }

        if (! $order->canBeReleasedFromHold()) {
            throw ValidationException::withMessages([
                'status' => ['Only orders currently on hold can be released.'],
            ]);
        }

        $order->releaseHold($actor);

        return ['order' => $order->fresh() ?? $order];
    }
}
