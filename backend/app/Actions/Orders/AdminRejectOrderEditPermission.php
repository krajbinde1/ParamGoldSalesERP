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

final class AdminRejectOrderEditPermission
{
    /**
     * Admin rejects a Director-approved request. The order stays locked.
     *
     * @return array{request: OrderEditPermissionRequest}
     */
    public function execute(Order $order, User $actor, string $remark): array
    {
        if (! Gate::forUser($actor)->allows('rejectDispatchedEditPermission', $order)) {
            throw new AuthorizationException('You are not allowed to reject this edit permission request.');
        }

        $remark = trim($remark);
        if (blank($remark) || mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remark is required (minimum 3 characters).'],
            ]);
        }

        if (mb_strlen($remark) > 2000) {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remark must not exceed 2000 characters.'],
            ]);
        }

        $rejected = DB::transaction(function () use ($order, $actor, $remark): OrderEditPermissionRequest {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

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
                'status' => OrderEditPermissionRequest::STATUS_REJECTED,
                'admin_reviewed_by' => $actor->id,
                'admin_reviewed_at' => Carbon::now('Asia/Kolkata'),
                'rejection_remark' => $remark,
            ]);

            return $locked->fresh() ?? $locked;
        });

        return ['request' => $rejected];
    }
}
