<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use App\Services\Notifications\OrderEditPermissionNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ApproveOrderEditPermission
{
    public function __construct(
        private readonly OrderEditPermissionNotifier $notifier = new OrderEditPermissionNotifier,
    ) {}

    /**
     * @return array{request: OrderEditPermissionRequest}
     */
    public function execute(OrderEditPermissionRequest $request, User $actor): array
    {
        if (! Gate::forUser($actor)->allows('approve', $request)) {
            throw new AuthorizationException('You are not allowed to approve this edit permission request.');
        }

        $approved = DB::transaction(function () use ($request, $actor): OrderEditPermissionRequest {
            /** @var OrderEditPermissionRequest $locked */
            $locked = OrderEditPermissionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending edit permission requests can be approved.'],
                ]);
            }

            /** @var Order $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['This order is no longer dispatched and cannot be unlocked for correction.'],
                ]);
            }

            $locked->update([
                'status' => OrderEditPermissionRequest::STATUS_APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => Carbon::now('Asia/Kolkata'),
                'rejection_remark' => null,
            ]);

            return $locked->fresh() ?? $locked;
        });

        $this->notifier->notifyAdminOfDecision($approved);

        return ['request' => $approved];
    }
}
