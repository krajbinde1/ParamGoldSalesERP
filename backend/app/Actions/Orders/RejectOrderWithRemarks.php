<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RejectOrderWithRemarks
{
    /**
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor, string $remark, string $rejectedByRole): array
    {
        if (! Gate::forUser($actor)->allows('reject', $order)) {
            throw new AuthorizationException('You are not authorized to reject this order.');
        }

        $remark = trim($remark);

        if ($remark === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Rejection remarks are required.'],
            ]);
        }

        if (mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Rejection remarks must be at least 3 characters.'],
            ]);
        }

        $order->reject($actor->id, $remark, $rejectedByRole);

        return ['order' => $order->fresh()];
    }
}
