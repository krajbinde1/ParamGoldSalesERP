<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ConfirmOrderEditPermission
{
    /**
     * Admin confirms a Director-approved request. Only then is editing unlocked.
     *
     * @return array{request: OrderEditPermissionRequest}
     */
    public function execute(Order $order, User $actor): array
    {
        if (! Gate::forUser($actor)->allows('confirmDispatchedEdit', $order)) {
            throw new AuthorizationException('You are not allowed to approve this edit permission request.');
        }

        $confirmed = DB::transaction(function () use ($order, $actor): OrderEditPermissionRequest {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['This order is no longer dispatched and cannot be unlocked for correction.'],
                ]);
            }

            /** @var OrderEditPermissionRequest|null $locked */
            $locked = OrderEditPermissionRequest::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', OrderEditPermissionRequest::STATUS_APPROVED)
                ->whereNull('admin_reviewed_at')
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isAwaitingAdminConfirmation()) {
                throw ValidationException::withMessages([
                    'status' => ['This edit permission is not waiting for Admin approval.'],
                ]);
            }

            $locked->update([
                'status' => OrderEditPermissionRequest::STATUS_ADMIN_APPROVED,
                'admin_reviewed_by' => $actor->id,
                'admin_reviewed_at' => Carbon::now('Asia/Kolkata'),
            ]);

            return $locked->fresh() ?? $locked;
        });

        return ['request' => $confirmed];
    }
}
