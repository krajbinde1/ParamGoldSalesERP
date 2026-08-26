<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OrderEditPermissionNotifier
{
    public const TYPE_REQUESTED = 'order_edit_permission_requested';

    public const TYPE_APPROVED = 'order_edit_permission_approved';

    public const TYPE_REJECTED = 'order_edit_permission_rejected';

    public function notifyDirectorsOfRequest(OrderEditPermissionRequest $request): void
    {
        $request->loadMissing([
            'order:id,order_no,status,dealer_id,grand_total',
            'order.dealer:id,firm_name',
            'requestedByUser:id,name',
        ]);

        $order = $request->order;
        if ($order === null) {
            return;
        }

        $shortNo = $order->shortOrderNo();
        $adminName = $request->requestedByUser?->name ?: 'Admin';
        $dealer = $order->dealer?->firm_name ?: 'Dealer';
        $title = 'Order Edit Permission Requested';
        $body = "{$adminName} requested permission to correct transport details on order {$shortNo} ({$dealer}).";

        foreach ($this->directorUsers() as $director) {
            $this->store($director, $order, self::TYPE_REQUESTED, $title, $body, [
                'request_id' => (string) $request->id,
                'requested_by' => $adminName,
                'reason' => (string) $request->reason,
                'route' => '/admin/order-edit-permission-requests/'.$request->id,
            ]);
        }
    }

    public function notifyAdminOfDecision(OrderEditPermissionRequest $request): void
    {
        $request->loadMissing([
            'order:id,order_no,status,dealer_id',
            'order.dealer:id,firm_name',
            'requestedByUser:id,name',
            'reviewedByUser:id,name',
        ]);

        $admin = $request->requestedByUser;
        $order = $request->order;
        if ($admin === null || $order === null) {
            return;
        }

        $shortNo = $order->shortOrderNo();
        $director = $request->reviewedByUser?->name ?: 'Director';

        if ($request->isApprovedUnused()) {
            $title = 'Order Edit Permission Unlocked';
            $body = "Admin confirmed the Director-approved correction for order {$shortNo}. You may now edit transport details once.";
            $type = self::TYPE_APPROVED;
        } elseif ($request->isAwaitingAdminConfirmation()) {
            $title = 'Order Edit Permission Approved by Director';
            $body = "{$director} approved the edit request for order {$shortNo}. Open Orders and use Approve Edit Permission to unlock a one-time correction.";
            $type = self::TYPE_APPROVED;
        } else {
            $title = 'Order Edit Permission Rejected';
            $remark = trim((string) $request->rejection_remark);
            $body = "{$director} rejected the edit permission for order {$shortNo}.";
            if ($remark !== '') {
                $body .= " Remark: {$remark}";
            }
            $type = self::TYPE_REJECTED;
        }

        $this->store($admin, $order, $type, $title, $body, [
            'request_id' => (string) $request->id,
            'reviewed_by' => $director,
            'route' => '/admin/orders/'.$order->id,
        ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function store(
        User $user,
        Order $order,
        string $type,
        string $title,
        string $body,
        array $data,
    ): void {
        try {
            AppNotification::query()->create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => array_merge([
                    'type' => $type,
                    'order_id' => (string) $order->id,
                    'order_no' => (string) $order->order_no,
                    'short_order_no' => $order->shortOrderNo(),
                    'status' => (string) $order->status,
                ], $data),
            ]);
        } catch (Throwable $e) {
            Log::warning('Order edit permission notify failed: '.$e->getMessage(), [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $type,
            ]);
        }
    }

    /**
     * @return list<User>
     */
    private function directorUsers(): array
    {
        return User::query()
            ->where('role', UserRole::Director->value)
            ->get()
            ->filter(fn (User $user): bool => ! $user->isAdminUser())
            ->unique('id')
            ->values()
            ->all();
    }
}
