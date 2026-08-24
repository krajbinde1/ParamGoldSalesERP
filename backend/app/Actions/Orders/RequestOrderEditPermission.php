<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use App\Services\Notifications\OrderEditPermissionNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RequestOrderEditPermission
{
    public function __construct(
        private readonly OrderEditPermissionNotifier $notifier = new OrderEditPermissionNotifier,
    ) {}

    /**
     * @return array{order: Order, request: OrderEditPermissionRequest}
     */
    public function execute(Order $order, User $actor, string $reason): array
    {
        if (! Gate::forUser($actor)->allows('requestDispatchedEdit', $order)) {
            throw new AuthorizationException('You are not allowed to request edit permission for this order.');
        }

        $reason = trim($reason);
        if (blank($reason) || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => ['Reason for Edit is required (minimum 3 characters).'],
            ]);
        }

        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => ['Reason for Edit must not exceed 2000 characters.'],
            ]);
        }

        $created = DB::transaction(function () use ($order, $actor, $reason): OrderEditPermissionRequest {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Order::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['Edit permission can only be requested for dispatched orders.'],
                ]);
            }

            $openExists = OrderEditPermissionRequest::query()
                ->where('order_id', $locked->id)
                ->open()
                ->lockForUpdate()
                ->exists();

            if ($openExists) {
                throw ValidationException::withMessages([
                    'reason' => ['An edit permission request is already pending or approved for this order.'],
                ]);
            }

            return OrderEditPermissionRequest::query()->create([
                'order_id' => $locked->id,
                'requested_by' => $actor->id,
                'reason' => $reason,
                'status' => OrderEditPermissionRequest::STATUS_PENDING,
            ]);
        });

        $this->notifier->notifyDirectorsOfRequest($created->fresh() ?? $created);

        return [
            'order' => $order->fresh() ?? $order,
            'request' => $created->fresh() ?? $created,
        ];
    }
}
