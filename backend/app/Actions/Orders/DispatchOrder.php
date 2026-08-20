<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DispatchOrder
{
    /**
     * Production Supervisor marks a billed order as dispatched.
     *
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor, ?string $remark = null): array
    {
        if (! Gate::forUser($actor)->allows('dispatch', $order)) {
            throw new AuthorizationException('You are not allowed to dispatch this order.');
        }

        if (! $order->canBeDispatched()) {
            throw ValidationException::withMessages([
                'status' => 'Only billed orders can be dispatched.',
            ]);
        }

        Validator::make(
            ['remark' => $remark],
            ['remark' => ['nullable', 'string', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($order, $actor, $remark): array {
            $order->dispatch(
                userId: $actor->id,
                remark: $remark,
            );

            return ['order' => $order->fresh()];
        });
    }
}
