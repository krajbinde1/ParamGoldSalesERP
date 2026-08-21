<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
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

    public const TYPE_ON_HOLD = 'order_on_hold';

    public const TYPE_REVERTED = 'order_reverted_to_manager';

    public const TYPE_REAPPROVED = 'order_reapproved';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifyNewOrder(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name,village', 'salesEmployee:id,full_name,reporting_manager_id']);

        $managerUser = $this->reportingManagerUser($order);
        if (! $managerUser) {
            Log::error('PARAMGOLD_LIVE_FCM NEW_ORDER_NO_MANAGER', [
                'order_id' => $order->id,
                'sales_employee_id' => $order->sales_employee_id,
                'reporting_manager_id' => $order->salesEmployee?->reporting_manager_id,
            ]);

            return;
        }

        Log::error('PARAMGOLD_LIVE_FCM NEW_ORDER_TRIGGER', [
            'order_id' => $order->id,
            'manager_user_id' => $managerUser->id,
            'manager_email' => $managerUser->email,
            'status' => (string) $order->status,
        ]);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $sales = $order->salesEmployee?->full_name ?: '-';

        $this->dispatchToUser(
            user: $managerUser,
            order: $order,
            type: self::TYPE_NEW_ORDER,
            statusKey: (string) $order->status,
            title: 'New Order for Approval',
            body: "{$sales} placed order {$shortNo} for {$dealer}",
            data: $this->baseData($order, self::TYPE_NEW_ORDER, [
                'sales_person_name' => $sales,
                'route' => "/manager/orders/{$order->id}",
                'action' => 'review',
                'channel_id' => 'paramgold_critical_alerts_v5',
                'fullscreen' => '1',
            ]),
            android: [
                'notification' => [
                    'channel_id' => 'paramgold_critical_alerts_v5',
                    'notification_priority' => 'PRIORITY_MAX',
                    'default_vibrate_timings' => true,
                    'sound' => 'default',
                ],
            ],
        );
    }

    public function notifyApproved(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'salesEmployee:id,full_name,reporting_manager_id',
            'approvedByUser:id,name',
        ]);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $managerName = $order->approvedByUser?->name
            ?: ($this->reportingManagerUser($order)?->name ?: 'Sales Manager');
        $body = "Order {$shortNo} for {$dealer} approved by {$managerName}";

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_APPROVED,
            title: 'Order Approved',
            body: $body,
            extra: [
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'paramgold_critical_alerts_v5',
            ],
        );

        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_APPROVED.'_production',
                statusKey: (string) $order->status,
                title: 'Order Approved',
                body: $body,
                data: $this->baseData($order, self::TYPE_APPROVED, [
                    'sales_person_name' => $order->salesEmployee?->full_name ?? '',
                    'route' => "/production/orders/{$order->id}",
                    'action' => 'view_order',
                    'channel_id' => 'paramgold_critical_alerts_v5',
                    'fullscreen' => '1',
                ]),
            );
        }
    }

    public function notifyOnHold(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'salesEmployee:id,full_name,reporting_manager_id',
            'heldByUser:id,name',
        ]);

        $shortNo = $order->shortOrderNo();
        $supervisor = $order->heldByUser?->name ?: 'Production Supervisor';
        $remark = filled($order->hold_remark) ? ' Remark: '.$order->hold_remark : '';
        $body = "Order {$shortNo} has been put on hold by Production Supervisor.{$remark}";
        $statusKey = 'on_hold:'.($order->held_at?->toJSON() ?? (string) $order->id);

        $manager = $this->reportingManagerUser($order);
        if ($manager) {
            $this->dispatchToUser(
                user: $manager,
                order: $order,
                type: self::TYPE_ON_HOLD,
                statusKey: $statusKey,
                title: 'Order On Hold',
                body: $body,
                data: $this->baseData($order, self::TYPE_ON_HOLD, [
                    'route' => "/manager/orders/{$order->id}",
                    'action' => 'view_order',
                    'hold_remark' => (string) ($order->hold_remark ?? ''),
                    'channel_id' => 'paramgold_critical_alerts_v5',
                ]),
            );
        }

        foreach ($this->adminUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_ON_HOLD.'_admin',
                statusKey: $statusKey,
                title: 'Order On Hold',
                body: $body,
                data: $this->baseData($order, self::TYPE_ON_HOLD, [
                    'route' => "/admin/orders/{$order->id}",
                    'action' => 'view_order',
                    'hold_remark' => (string) ($order->hold_remark ?? ''),
                    'channel_id' => 'paramgold_critical_alerts_v5',
                ]),
            );
        }
    }

    public function notifyReverted(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'salesEmployee:id,full_name,reporting_manager_id',
            'revertedByUser:id,name',
        ]);

        $shortNo = $order->shortOrderNo();
        $remark = filled($order->revert_remark) ? ' Remark: '.$order->revert_remark : '';
        $body = "Order {$shortNo} has been returned by Production Supervisor for review.{$remark}";
        $statusKey = 'reverted:'.($order->reverted_at?->toJSON() ?? (string) $order->id);

        $manager = $this->reportingManagerUser($order);
        if ($manager === null) {
            return;
        }

        $this->dispatchToUser(
            user: $manager,
            order: $order,
            type: self::TYPE_REVERTED,
            statusKey: $statusKey,
            title: 'Order Returned by Production',
            body: $body,
            data: $this->baseData($order, self::TYPE_REVERTED, [
                'route' => "/manager/orders/{$order->id}",
                'action' => 'review',
                'revert_remark' => (string) ($order->revert_remark ?? ''),
                'channel_id' => 'paramgold_critical_alerts_v5',
                'fullscreen' => '1',
            ]),
            android: [
                'notification' => [
                    'channel_id' => 'paramgold_critical_alerts_v5',
                    'notification_priority' => 'PRIORITY_MAX',
                    'default_vibrate_timings' => true,
                    'sound' => 'default',
                ],
            ],
        );
    }

    public function notifyReapproved(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'salesEmployee:id,full_name',
            'reapprovedByUser:id,name',
            'approvedByUser:id,name',
        ]);

        $shortNo = $order->shortOrderNo();
        $managerName = $order->reapprovedByUser?->name
            ?: ($order->approvedByUser?->name ?: 'Sales Manager');
        $body = "Order {$shortNo} has been re-approved by Manager and is ready for processing.";
        $statusKey = 'reapproved:'.($order->reapproved_at?->toJSON() ?? (string) $order->id);

        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_REAPPROVED.'_production',
                statusKey: $statusKey,
                title: 'Order Re-Approved',
                body: $body,
                data: $this->baseData($order, self::TYPE_REAPPROVED, [
                    'sales_person_name' => $order->salesEmployee?->full_name ?? '',
                    'manager_name' => $managerName,
                    'route' => "/production/orders/{$order->id}",
                    'action' => 'view_order',
                    'channel_id' => 'paramgold_critical_alerts_v5',
                    'fullscreen' => '1',
                ]),
            );
        }
    }

    public function notifyRejected(Order $order): void
    {
        if ($order->rejected_by_role === Order::REJECTED_BY_ROLE_ADMIN) {
            $this->notifyAdminRejected($order);

            return;
        }

        $this->notifyManagerRejected($order);
    }

    public function notifySentForBilling(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name,village', 'salesEmployee:id,full_name']);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $body = "Order {$shortNo} for {$dealer} has been sent for billing.";

        foreach ($this->adminUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_SENT_FOR_BILL,
                statusKey: (string) $order->status,
                title: 'Order Ready for Billing',
                body: $body,
                data: $this->baseData($order, self::TYPE_SENT_FOR_BILL, [
                    'route' => '/dashboard',
                    'action' => 'view_order',
                    'channel_id' => 'paramgold_critical_alerts_v5',
                    'fullscreen' => '0',
                    'vehicle_number' => (string) ($order->vehicle_number ?? ''),
                ]),
            );
        }
    }

    public function notifyBilled(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name,village', 'salesEmployee:id,full_name,reporting_manager_id']);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $body = "Order {$shortNo} for {$dealer} has been billed.";
        $billNumber = (string) ($order->bill_number ?? '');
        $extra = [
            'bill_number' => $billNumber,
            'bill_url' => (string) ($order->billUrl() ?? ''),
            'action' => 'view_order',
            'channel_id' => 'paramgold_critical_alerts_v5',
            'fullscreen' => '0',
        ];

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_BILLED,
            title: 'Order Billed',
            body: $body,
            extra: array_merge($extra, ['route' => "/orders/{$order->id}"]),
        );

        $this->notifyManagerStatus(
            order: $order,
            type: self::TYPE_BILLED,
            title: 'Order Billed',
            body: $body,
            extra: array_merge($extra, ['route' => "/manager/orders/{$order->id}"]),
        );

        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_BILLED.'_production',
                statusKey: (string) $order->status,
                title: 'Order Billed',
                body: $body,
                data: $this->baseData($order, self::TYPE_BILLED, array_merge($extra, [
                    'route' => "/production/orders/{$order->id}",
                    'fullscreen' => '1',
                ])),
            );
        }
    }

    public function notifyDispatched(Order $order): void
    {
        $order->loadMissing(['dealer:id,firm_name,village', 'salesEmployee:id,full_name,reporting_manager_id']);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $body = "Order {$shortNo} for {$dealer} has been dispatched.";
        $remark = trim((string) ($order->dispatch_remark ?? ''));
        $extra = [
            'remark' => $remark,
            'dispatch_remark' => $remark,
            'action' => 'view_order',
            'channel_id' => 'paramgold_critical_alerts_v5',
            'fullscreen' => '0',
        ];

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_DISPATCHED,
            title: 'Order Dispatched',
            body: $body,
            extra: array_merge($extra, ['route' => "/orders/{$order->id}"]),
        );

        $this->notifyManagerStatus(
            order: $order,
            type: self::TYPE_DISPATCHED,
            title: 'Order Dispatched',
            body: $body,
            extra: array_merge($extra, ['route' => "/manager/orders/{$order->id}"]),
        );

        foreach ($this->adminUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_DISPATCHED.'_admin',
                statusKey: (string) $order->status,
                title: 'Order Dispatched',
                body: $body,
                data: $this->baseData($order, self::TYPE_DISPATCHED, array_merge($extra, [
                    'route' => '/dashboard',
                ])),
            );
        }
    }

    private function notifyManagerRejected(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'rejectedByUser:id,name',
            'salesEmployee:id,full_name',
        ]);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $managerName = $order->rejectedByUser?->name
            ?: ($order->rejected_by_role ?: 'Sales Manager');
        $reason = trim((string) ($order->rejection_remark ?? ''));

        $body = "Order {$shortNo} for {$dealer} rejected by {$managerName}";
        if ($reason !== '') {
            $body .= "\nReason: {$reason}";
        }

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_REJECTED,
            title: 'Order Rejected',
            body: $body,
            extra: [
                'remark' => $reason,
                'rejection_reason' => $reason,
                'rejected_by' => $managerName,
                'route' => "/orders/{$order->id}",
                'action' => 'view_order',
                'channel_id' => 'paramgold_critical_alerts_v5',
            ],
        );
    }

    private function notifyAdminRejected(Order $order): void
    {
        $order->loadMissing([
            'dealer:id,firm_name,village',
            'salesEmployee:id,full_name,reporting_manager_id',
            'rejectedByUser:id,name',
        ]);

        $shortNo = $order->shortOrderNo();
        $dealer = $this->dealerName($order);
        $reason = trim((string) ($order->rejection_remark ?? ''));

        $body = "Order {$shortNo} for {$dealer} was rejected by Admin.";
        if ($reason !== '') {
            $body .= "\nReason: {$reason}";
        }

        $extra = [
            'remark' => $reason,
            'rejection_reason' => $reason,
            'rejected_by' => $order->rejectedByUser?->name ?: 'Admin',
            'action' => 'view_order',
            'channel_id' => 'paramgold_critical_alerts_v5',
            'fullscreen' => '0',
        ];

        $this->notifySalesEmployee(
            order: $order,
            type: self::TYPE_REJECTED,
            title: 'Order Rejected by Admin',
            body: $body,
            extra: array_merge($extra, ['route' => "/orders/{$order->id}"]),
        );

        $this->notifyManagerStatus(
            order: $order,
            type: self::TYPE_REJECTED,
            title: 'Order Rejected by Admin',
            body: $body,
            extra: array_merge($extra, ['route' => "/manager/orders/{$order->id}"]),
        );

        foreach ($this->productionSupervisorUsers() as $user) {
            $this->dispatchToUser(
                user: $user,
                order: $order,
                type: self::TYPE_REJECTED.'_production',
                statusKey: (string) $order->status,
                title: 'Order Rejected by Admin',
                body: $body,
                data: $this->baseData($order, self::TYPE_REJECTED, array_merge($extra, [
                    'route' => "/production/orders/{$order->id}",
                ])),
            );
        }
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notifySalesEmployee(
        Order $order,
        string $type,
        string $title,
        string $body,
        array $extra = [],
    ): void {
        $order->loadMissing(['dealer:id,firm_name,village', 'salesEmployee.user']);
        $salesUser = $order->salesEmployee?->user;
        if (! $salesUser instanceof User) {
            $salesUser = User::query()->where('employee_id', $order->sales_employee_id)->first();
        }
        if (! $salesUser) {
            return;
        }

        $this->dispatchToUser(
            user: $salesUser,
            order: $order,
            type: $type,
            statusKey: (string) $order->status,
            title: $title,
            body: $body,
            data: $this->baseData($order, $type, array_merge([
                'sales_person_name' => $order->salesEmployee?->full_name ?? '',
                'fullscreen' => '0',
            ], $extra)),
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
        $managerUser = $this->reportingManagerUser($order);
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
            data: $this->baseData($order, $type, array_merge([
                'fullscreen' => '0',
            ], $extra)),
        );
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function baseData(Order $order, string $type, array $extra = []): array
    {
        $eventAt = $order->updated_at?->toIso8601String()
            ?? $order->order_date?->format('c')
            ?? now()->toIso8601String();

        return array_merge([
            'type' => $type,
            'order_id' => (string) $order->id,
            'order_no' => (string) $order->order_no,
            'short_order_no' => $order->shortOrderNo(),
            'status' => (string) $order->status,
            'status_label' => $order->displayStatusLabel(),
            'dealer_name' => $this->dealerName($order),
            'dealer_village' => (string) ($order->dealer?->village ?? ''),
            'amount' => (string) ($order->grand_total ?? ''),
            'grand_total' => (string) ($order->grand_total ?? ''),
            'event_at' => (string) $eventAt,
            'order_date' => (string) ($order->order_date?->format('Y-m-d') ?? ''),
        ], $extra);
    }

    private function dealerName(Order $order): string
    {
        return $order->dealer?->firm_name ?: '-';
    }

    private function reportingManagerUser(Order $order): ?User
    {
        $order->loadMissing(['salesEmployee:id,reporting_manager_id']);
        $managerEmployeeId = $order->salesEmployee?->reporting_manager_id;
        if (! $managerEmployeeId) {
            return null;
        }

        // LOGIN ROLE is authoritative — designation/job_role must not decide routing.
        $user = User::query()
            ->where('employee_id', $managerEmployeeId)
            ->where('role', UserRole::Manager->value)
            ->first();

        if ($user === null) {
            Log::error('PARAMGOLD_LIVE_FCM MANAGER_LOGIN_ROLE_MISMATCH', [
                'order_id' => $order->id,
                'reporting_manager_employee_id' => $managerEmployeeId,
                'linked_user_role' => User::query()
                    ->where('employee_id', $managerEmployeeId)
                    ->value('role'),
            ]);
        }

        return $user;
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
                Log::error('PARAMGOLD_LIVE_FCM DEDUPE_SKIP', [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'type' => $type,
                    'status_key' => $statusKey,
                ]);

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

            Log::error('PARAMGOLD_LIVE_FCM MANAGER_TOKENS', [
                'user_id' => $user->id,
                'type' => $type,
                'order_id' => $order->id,
                'token_count' => count($tokens),
                'token_suffixes' => array_map(
                    static fn (string $token): string => substr($token, -12),
                    $tokens,
                ),
                'fullscreen' => (string) ($data['fullscreen'] ?? ''),
            ]);

            if ($tokens === []) {
                Log::error('PARAMGOLD_LIVE_FCM MANAGER_TOKEN_MISSING', [
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => $type,
                ]);

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

            Log::error('PARAMGOLD_LIVE_FCM DISPATCH_RESULT', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => $type,
                'success' => $result['success'],
                'failure' => $result['failure'],
                'invalid' => count($result['invalid_tokens']),
            ]);

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

    /**
     * @return list<User>
     */
    private function productionSupervisorUsers(): array
    {
        // LOGIN ROLE only — do not use job_role / designation text.
        return User::query()
            ->with('employee:id,status')
            ->where('role', UserRole::ProductionSupervisor->value)
            ->get()
            ->filter(fn (User $user): bool => $user->employee === null || $user->employee->status === true)
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @return list<User>
     */
    private function adminUsers(): array
    {
        // Prefer LOGIN ROLE = admin. Also accept legacy Filament accounts that
        // store Admin on job_role (seeded role may still be employee) but never
        // use designation / "Regional Manager"-style text.
        // Directors (login role) are excluded from these order admin pushes.
        return User::query()
            ->with('employee:id,status')
            ->where(function ($query): void {
                $query->where('role', 'admin')
                    ->orWhere(function ($legacy): void {
                        $legacy->where('job_role', 'Admin')
                            ->where('role', '!=', UserRole::Director->value);
                    });
            })
            ->get()
            ->filter(fn (User $user): bool => ! $user->hasRole(UserRole::Director))
            ->filter(fn (User $user): bool => $user->employee === null || $user->employee->status === true)
            ->unique('id')
            ->values()
            ->all();
    }
}
