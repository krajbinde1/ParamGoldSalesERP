<?php

namespace App\Actions\Orders;

use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use App\Services\Notifications\OrderEditPermissionNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RejectOrderEditPermission
{
    public function __construct(
        private readonly OrderEditPermissionNotifier $notifier = new OrderEditPermissionNotifier,
    ) {}

    /**
     * @return array{request: OrderEditPermissionRequest}
     */
    public function execute(OrderEditPermissionRequest $request, User $actor, string $remark): array
    {
        if (! Gate::forUser($actor)->allows('reject', $request)) {
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

        $rejected = DB::transaction(function () use ($request, $actor, $remark): OrderEditPermissionRequest {
            /** @var OrderEditPermissionRequest $locked */
            $locked = OrderEditPermissionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending edit permission requests can be rejected.'],
                ]);
            }

            $locked->update([
                'status' => OrderEditPermissionRequest::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => Carbon::now('Asia/Kolkata'),
                'rejection_remark' => $remark,
            ]);

            return $locked->fresh() ?? $locked;
        });

        $this->notifier->notifyAdminOfDecision($rejected);

        return ['request' => $rejected];
    }
}
