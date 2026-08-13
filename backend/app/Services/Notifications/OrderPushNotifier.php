<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OrderPushNotifier
{
    public const TYPE_NEW_ORDER = 'new_order';

    public const TYPE_APPROVED = 'order_approved';

    public const TYPE_REJECTED = 'order_rejected';

    public const TYPE_SENT_FOR_BILL = 'order_sent_for_bill';

    public const TYPE_BILLED = 'order_billed';

    public const TYPE_DISPATCHED = 'order_dispatched';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifyNewOrder(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name,reporting_manager_id']);
        $managerEmployeeId = $order->salesEmployee?->reporting_manager_id;
        if (! $managerEmployeeId) {
            return;
        }

        $managerUser = User::query()->where('employee_id', $managerEmployeeId)->first();
        if (! $managerUser) {
            return;
        }

        $dealer = $order->dealer?->firm_name ?? '-';
        $sales = $order->salesEmployee?->full_name ?? '-';
        $amount = number_format((float) $order->grand_total, 2);
        $when = $this->formatDateTime($order->created_at ?? now());

        $title = 'NEW ORDER';
        $body = "Order {$order->order_no}\nDealer: {$dealer}\nSales: {$sales}\nAmount: ₹{$amount}\n{$when}";

        $this->dispatchToUser(
            user: $managerUser,
            order: $order,
            type: self::TYPE_NEW_ORDER,
            statusKey: (string) $order->status,
            title: $title,
            body: $body,
            data: [
                'type' => self::TYPE_NEW_ORDER,
                'order_id' => (string) $order->id,
                'order_no' => (string) $order->order_no,
                'dealer_name' => $dealer,
                'sales_person_name' => $sales,
                'order_amount' => (string) $order->grand_total,
                'order_datetime' => $when,
                'status' => (string) $order->status,
                'status_label' => $order->displayStatusLabel(),
                'route' => "/manager/orders/{$order->id}",
                'action' => 'review',
                'channel_id' => 'order_approvals',
                'fullscreen' => '1',
                'timeline' => $this->timelineText($order),
            ],
            android: [
                'notification' => [
                    'channel_id' => 'order_approvals',
                    'notification_priority' => 'PRIORITY_MAX',
                    'default_vibrate_timings' => true,
                    'sound' => 'default',
                ],
            ],
        );
    }

    public function notifyApproved(Order $order): void
    {
        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_APPROVED,
            title: 'ORDER APPROVED',
            bodyBuilder: function (Order $order, string $dealer): string {
                return "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Approved by Sales Manager";
            },
            extra: [
                'status_label' => 'Approved by Sales Manager',
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );

        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name']);
        $dealer = $order->dealer?->firm_name ?? '-';
        $sales = $order->salesEmployee?->full_name ?? '-';
        $body = "Order #{$order->order_no}\nDealer: {$dealer}\nSales: {$sales}";

        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_APPROVED.'_production',
                statusKey: (string) $order->status,
                title: 'ORDER APPROVED',
                body: $body,
                data: [
                    'type' => self::TYPE_APPROVED,
                    'order_id' => (string) $order->id,
                    'order_no' => (string) $order->order_no,
                    'dealer_name' => $dealer,
                    'sales_person_name' => $sales,
                    'status' => (string) $order->status,
                    'status_label' => 'Approved by Sales Manager',
                    'route' => "/production/orders/{$order->id}",
                    'action' => 'view_order',
                    'channel_id' => 'order_status',
                    'timeline' => $this->timelineText($order),
                    'fullscreen' => '0',
                ],
            );
        }
    }

    public function notifySentForBilling(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name']);
        $dealer = $order->dealer?->firm_name ?? '-';
        $body = "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Pending for Billing\nVehicle: ".($order->vehicle_number ?: '-');

        foreach ($this->adminUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_SENT_FOR_BILL,
                statusKey: (string) $order->status,
                title: 'PENDING FOR BILLING',
                body: $body,
                data: [
                    'type' => self::TYPE_SENT_FOR_BILL,
                    'order_id' => (string) $order->id,
                    'order_no' => (string) $order->order_no,
                    'dealer_name' => $dealer,
                    'status' => (string) $order->status,
                    'status_label' => 'Pending for Billing',
                    'route' => '/dashboard',
                    'action' => 'view_order',
                    'channel_id' => 'order_status',
                    'timeline' => $this->timelineText($order),
                    'fullscreen' => '0',
                ],
            );
        }
    }

    public function notifyRejected(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name', 'rejectedByUser:id,name', 'salesEmployee:id,full_name']);
        $dealer = $order->dealer?->firm_name ?? '-';
        $rejectedBy = $order->rejectedByUser?->name ?? ($order->rejected_by_role ?? 'Manager');
        $reason = $order->rejection_remark ?: '-';
        $when = $this->formatDateTime($order->rejected_at);

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_REJECTED,
            title: 'ORDER REJECTED',
            bodyBuilder: function () use ($order, $dealer, $rejectedBy, $reason, $when): string {
                return "Order #{$order->order_no}\nDealer: {$dealer}\nRejected By: {$rejectedBy}\nReason: {$reason}\n{$when}";
            },
            extra: [
                'status_label' => $order->displayStatusLabel(),
                'rejection_reason' => $reason,
                'rejected_by' => $rejectedBy,
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );
    }

    public function notifyBilled(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name,reporting_manager_id']);
        $dealer = $order->dealer?->firm_name ?? '-';
        $when = $this->formatDateTime($order->billed_at);
        $billNo = $order->bill_number ?: '-';

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_BILLED,
            title: 'ORDER BILLED',
            bodyBuilder: function () use ($order, $dealer, $billNo, $when): string {
                return "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Billed\nBill No: {$billNo}\n{$when}";
            },
            extra: [
                'status_label' => 'Billed',
                'bill_number' => (string) ($order->bill_number ?? ''),
                'bill_url' => (string) ($order->billUrl() ?? ''),
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );

        $this->notifyManagerStatus(
            order: $order,
            type: self::TYPE_BILLED,
            title: 'ORDER BILLED',
            body: "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Billed",
            extra: [
                'status_label' => 'Billed',
                'bill_number' => (string) ($order->bill_number ?? ''),
                'bill_url' => (string) ($order->billUrl() ?? ''),
                'route' => "/manager/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );

        $productionBody = "Order #{$order->order_no} | Dealer: {$dealer} | Bill No: {$billNo}";
        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_BILLED.'_production',
                statusKey: (string) $order->status,
                title: 'ORDER BILLED',
                body: $productionBody,
                data: [
                    'type' => self::TYPE_BILLED,
                    'order_id' => (string) $order->id,
                    'order_no' => (string) $order->order_no,
                    'dealer_name' => $dealer,
                    'bill_number' => (string) ($order->bill_number ?? ''),
                    'bill_url' => (string) ($order->billUrl() ?? ''),
                    'status' => (string) $order->status,
                    'status_label' => 'Billed',
                    'route' => "/production/orders/{$order->id}",
                    'action' => 'view_order',
                    'channel_id' => 'order_status',
                    'timeline' => $this->timelineText($order),
                    'fullscreen' => '0',
                ],
            );
        }
    }

    public function notifyDispatched(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee:id,full_name,reporting_manager_id']);
        $dealer = $order->dealer?->firm_name ?? '-';
        $when = $this->formatDateTime($order->dispatched_at ?? $order->dispatch_date);
        $transport = trim(implode(' · ', array_filter([
            $order->transporter_name,
            $order->vehicle_number,
            $order->lr_number ? 'LR '.$order->lr_number : null,
        ]))) ?: 'Dispatch completed';

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_DISPATCHED,
            title: 'ORDER DISPATCHED',
            bodyBuilder: function () use ($order, $dealer, $when, $transport): string {
                return "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Dispatched\n{$when}\n{$transport}";
            },
            extra: [
                'status_label' => 'Dispatched',
                'transport_details' => $transport,
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );

        $this->notifyManagerStatus(
            order: $order,
            type: self::TYPE_DISPATCHED,
            title: 'ORDER DISPATCHED',
            body: "Order #{$order->order_no}\nDealer: {$dealer}\nStatus: Dispatched",
            extra: [
                'status_label' => 'Dispatched',
                'transport_details' => $transport,
                'route' => "/manager/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'order_status',
            ],
        );
    }

    /**
     * @param  callable(Order, string): string  $bodyBuilder
     * @param  array<string, string>  $extra
     */
    private function notifySalesEmployee(
        Order $order,
        string $type,
        string $title,
        callable $bodyBuilder,
        array $extra = [],
    ): void {
        $order->loadMissing(['dealer:id,firm_name', 'salesEmployee.user']);
        $salesUser = $order->salesEmployee?->user;
        if (! $salesUser instanceof User) {
            $salesUser = User::query()->where('employee_id', $order->sales_employee_id)->first();
        }
        if (! $salesUser) {
            return;
        }

        $dealer = $order->dealer?->firm_name ?? '-';
        $body = $bodyBuilder($order, $dealer);

        $this->dispatchToUser(
            user: $salesUser,
            order: $order,
            type: $type,
            statusKey: (string) $order->status,
            title: $title,
            body: $body,
            data: array_merge([
                'type' => $type,
                'order_id' => (string) $order->id,
                'order_no' => (string) $order->order_no,
                'dealer_name' => $dealer,
                'sales_person_name' => $order->salesEmployee?->full_name ?? '',
                'status' => (string) $order->status,
                'timeline' => $this->timelineText($order),
                'fullscreen' => '0',
            ], $extra),
        );
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notifyManagerStatus(
        Order $order,
        string $type,
        string $title,
        string $body,
        array $extra = [],
    ): void {
        $order->loadMissing(['salesEmployee:id,reporting_manager_id']);
        $managerEmployeeId = $order->salesEmployee?->reporting_manager_id;
        if (! $managerEmployeeId) {
            return;
        }

        $managerUser = User::query()->where('employee_id', $managerEmployeeId)->first();
        if (! $managerUser) {
            return;
        }

        $this->dispatchToUser(
            user: $managerUser,
            order: $order,
            type: $type.'_manager',
            statusKey: (string) $order->status,
            title: $title,
            body: $body,
            data: array_merge([
                'type' => $type,
                'order_id' => (string) $order->id,
                'order_no' => (string) $order->order_no,
                'dealer_name' => $order->dealer?->firm_name ?? '-',
                'status' => (string) $order->status,
                'timeline' => $this->timelineText($order),
                'fullscreen' => '0',
            ], $extra),
        );
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $android
     */
    private function dispatchToUser(
        User $user,
        Order $order,
        string $type,
        string $statusKey,
        string $title,
        string $body,
        array $data,
        array $android = [],
    ): void {
        try {
            $inserted = DB::table('order_push_dedupe')->insertOrIgnore([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $type,
                'status_key' => $statusKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            AppNotification::query()->create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

            $tokens = DeviceToken::query()
                ->where('user_id', $user->id)
                ->pluck('token')
                ->all();

            if ($tokens === []) {
                return;
            }

            $result = $this->fcm->sendToTokens(
                tokens: $tokens,
                notification: [
                    'title' => $title,
                    'body' => $body,
                ],
                data: $data,
                android: $android,
            );

            if ($result['invalid_tokens'] !== []) {
                DeviceToken::query()
                    ->whereIn('token', $result['invalid_tokens'])
                    ->delete();
            }
        } catch (Throwable $e) {
            Log::warning('Order push notify failed: '.$e->getMessage(), [
                'order_id' => $order->id,
                'type' => $type,
                'user_id' => $user->id,
            ]);
        }
    }

    private function timelineText(Order $order): string
    {
        $steps = $order->workflowTimeline();
        $lines = [];
        foreach ($steps as $step) {
            if (! empty($step['is_rejection'])) {
                $lines[] = ($step['label'] ?? 'Rejected').' ✓';
                continue;
            }
            $mark = ! empty($step['completed']) ? '✓' : '○';
            $label = match ($step['key'] ?? '') {
                'created' => 'Order Placed',
                'approved' => 'Approved',
                'pending_for_billing' => 'Pending for Billing',
                'billed' => 'Billed',
                'dispatched' => 'Dispatched',
                default => (string) ($step['label'] ?? ''),
            };
            $lines[] = "{$label} {$mark}";
        }

        return implode("\n", $lines);
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return Carbon::now('Asia/Kolkata')->format('d M Y, h:i A');
        }

        return Carbon::parse($value)->timezone('Asia/Kolkata')->format('d M Y, h:i A');
    }

    /**
     * @return list<User>
     */
    private function productionSupervisorUsers(): array
    {
        return User::query()
            ->where('role', 'production_supervisor')
            ->get()
            ->all();
    }

    /**
     * @return list<User>
     */
    private function adminUsers(): array
    {
        // Filament Admin users are identified by job_role = Admin (see User::isAdminUser()).
        return User::query()
            ->where('job_role', 'Admin')
            ->get()
            ->all();
    }
}
