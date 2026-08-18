<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentRequestPushNotifier
{
    /** Wire type for new / next-stage approval required (critical). */
    public const TYPE_APPROVAL = 'payment_approval';

    /** Wire type for admin reminder (critical). */
    public const TYPE_REMINDER = 'payment_approval_reminder';

    /** @deprecated Prefer TYPE_APPROVAL — kept for inbox/history compatibility. */
    public const TYPE_APPROVAL_REQUIRED = 'payment_approval_required';

    /** @deprecated Prefer TYPE_REMINDER */
    public const TYPE_REMINDER_LEGACY = 'payment_request_reminder';

    public const TYPE_CREATED = 'payment_request_created';

    public const TYPE_FIRST_APPROVED = 'payment_request_first_approved';

    public const TYPE_FINAL_APPROVED = 'payment_request_final_approved';

    public const TYPE_REJECTED_FIRST = 'payment_request_rejected_first';

    public const TYPE_REJECTED_SECOND = 'payment_request_rejected_second';

    public const TYPE_PAYMENT_DONE = 'payment_request_payment_done';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
        private readonly PaymentRequestApproverResolver $approvers = new PaymentRequestApproverResolver,
    ) {}

    public function notifyCreated(PaymentRequest $paymentRequest): void
    {
        $user = $this->approvers->firstApprover();
        if (! $user) {
            Log::warning('Payment approval notify skipped: first approver not resolved', [
                'payment_request_id' => $paymentRequest->id,
            ]);

            return;
        }

        $this->notifyApproverCritical(
            user: $user,
            paymentRequest: $paymentRequest,
            wireType: self::TYPE_APPROVAL,
            dedupeType: self::TYPE_CREATED,
            title: 'Payment Approval Required',
            body: $this->vendorAmountBody($paymentRequest),
            reminderCountForDedupe: null,
        );
    }

    public function notifyFirstApproved(PaymentRequest $paymentRequest): void
    {
        $user = $this->approvers->secondApprover();
        if (! $user) {
            Log::warning('Payment approval notify skipped: second approver not resolved', [
                'payment_request_id' => $paymentRequest->id,
            ]);

            return;
        }

        $this->notifyApproverCritical(
            user: $user,
            paymentRequest: $paymentRequest,
            wireType: self::TYPE_APPROVAL,
            dedupeType: self::TYPE_FIRST_APPROVED,
            title: 'Payment Approval Required',
            body: $this->vendorAmountBody($paymentRequest)."\nFirst approval completed. Your approval is required.",
            reminderCountForDedupe: null,
        );
    }

    /**
     * Send reminder for one pending request to the current stage approver.
     *
     * @return array{sent: bool, reason: string}
     */
    public function notifyReminderForRequest(PaymentRequest $paymentRequest, int $reminderSequence): array
    {
        if (! $paymentRequest->isAwaitingApproval()) {
            return ['sent' => false, 'reason' => 'not_pending'];
        }

        $user = match ((string) $paymentRequest->status) {
            PaymentRequest::STATUS_PENDING_FIRST => $this->approvers->firstApprover(),
            PaymentRequest::STATUS_PENDING_SECOND => $this->approvers->secondApprover(),
            default => null,
        };

        if (! $user) {
            return ['sent' => false, 'reason' => 'approver_missing'];
        }

        $ok = $this->notifyApproverCritical(
            user: $user,
            paymentRequest: $paymentRequest,
            wireType: self::TYPE_REMINDER,
            dedupeType: self::TYPE_REMINDER.'_'.$paymentRequest->id.'_'.$reminderSequence,
            title: 'Payment Approval Reminder',
            body: $this->vendorAmountBody($paymentRequest)."\nApproval is still pending.",
            reminderCountForDedupe: $reminderSequence,
            requireFcmSuccess: true,
        );

        return $ok
            ? ['sent' => true, 'reason' => 'ok']
            : ['sent' => false, 'reason' => 'fcm_failed'];
    }

    /**
     * @param  Collection<int, PaymentRequest>  $requests
     * @return array{sent: int, failed: int}
     */
    public function notifyReminder(Collection $requests): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($requests as $request) {
            $sequence = max(1, (int) $request->reminder_count);
            $result = $this->notifyReminderForRequest($request, $sequence);
            if ($result['sent']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function notifyRejectedByFirst(PaymentRequest $paymentRequest): void
    {
        $reason = trim((string) ($paymentRequest->first_rejection_remark ?? ''));
        $body = "Payment request {$paymentRequest->request_no} was rejected by First Approver.";
        if ($reason !== '') {
            $body .= " Reason: {$reason}";
        }

        foreach ($this->adminUsers() as $user) {
            $this->dispatchStatusUpdate(
                user: $user,
                paymentRequest: $paymentRequest,
                type: self::TYPE_REJECTED_FIRST,
                title: 'Payment Request Rejected',
                body: $body,
                extra: ['remark' => $reason],
            );
        }
    }

    public function notifyRejectedBySecond(PaymentRequest $paymentRequest): void
    {
        $reason = trim((string) ($paymentRequest->second_rejection_remark ?? ''));
        $body = "Payment request {$paymentRequest->request_no} was rejected by Second Approver.";
        if ($reason !== '') {
            $body .= " Reason: {$reason}";
        }

        foreach ($this->adminUsers() as $user) {
            $this->dispatchStatusUpdate(
                user: $user,
                paymentRequest: $paymentRequest,
                type: self::TYPE_REJECTED_SECOND,
                title: 'Payment Request Rejected',
                body: $body,
                extra: ['remark' => $reason],
            );
        }

        $first = $this->approvers->firstApprover();
        if ($first) {
            $this->dispatchStatusUpdate(
                user: $first,
                paymentRequest: $paymentRequest,
                type: self::TYPE_REJECTED_SECOND.'_first',
                title: 'Payment Request Rejected',
                body: $body,
                extra: ['remark' => $reason],
            );
        }
    }

    public function notifyFinalApproved(PaymentRequest $paymentRequest): void
    {
        foreach ($this->adminUsers() as $user) {
            $this->dispatchStatusUpdate(
                user: $user,
                paymentRequest: $paymentRequest,
                type: self::TYPE_FINAL_APPROVED,
                title: 'Payment Request Approved',
                body: "Payment request {$paymentRequest->request_no} for {$paymentRequest->vendor_name} is approved for payment.",
            );
        }
    }

    public function notifyPaymentDone(PaymentRequest $paymentRequest): void
    {
        $body = "Payment done for request {$paymentRequest->request_no} ({$paymentRequest->vendor_name}).";

        foreach (array_filter([
            $this->approvers->firstApprover(),
            $this->approvers->secondApprover(),
        ]) as $user) {
            $this->dispatchStatusUpdate(
                user: $user,
                paymentRequest: $paymentRequest,
                type: self::TYPE_PAYMENT_DONE,
                title: 'Payment Done',
                body: $body,
            );
        }
    }

    private function vendorAmountBody(PaymentRequest $paymentRequest): string
    {
        $vendor = trim((string) $paymentRequest->vendor_name);
        if ($vendor === '') {
            $vendor = 'Vendor Not Available';
        }
        $amount = '₹'.number_format((float) $paymentRequest->amount, 0, '.', ',');

        return "{$vendor} • {$amount}";
    }

    /**
     * Critical per-request FCM + inbox for the current Director approver.
     */
    private function notifyApproverCritical(
        User $user,
        PaymentRequest $paymentRequest,
        string $wireType,
        string $dedupeType,
        string $title,
        string $body,
        ?int $reminderCountForDedupe,
        bool $requireFcmSuccess = false,
    ): bool {
        try {
            $status = (string) $paymentRequest->status;
            $statusKey = $reminderCountForDedupe !== null
                ? 'reminder_'.$reminderCountForDedupe
                : $status;

            $inserted = DB::table('payment_request_push_dedupe')->insertOrIgnore([
                'payment_request_id' => $paymentRequest->id,
                'user_id' => $user->id,
                'type' => $dedupeType,
                'status_key' => $statusKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $route = '/director/payment-requests/'.$paymentRequest->id;

            $data = [
                'type' => $wireType,
                'payment_request_id' => (string) $paymentRequest->id,
                'request_no' => (string) $paymentRequest->request_no,
                'vendor_name' => (string) $paymentRequest->vendor_name,
                'amount' => (string) $paymentRequest->amount,
                'pending_count' => '1',
                'pending_amount' => (string) $paymentRequest->amount,
                'status' => $status,
                'status_label' => $paymentRequest->displayStatusLabel(),
                'approval_stage' => $status === PaymentRequest::STATUS_PENDING_FIRST
                    ? 'First Approval'
                    : 'Second Approval',
                'event_at' => (string) ($paymentRequest->updated_at?->toIso8601String()
                    ?? now()->toIso8601String()),
                'route' => $route,
                'action' => 'review',
                'channel_id' => 'paramgold_critical_alerts_v5',
                'fullscreen' => '1',
            ];

            if ($reminderCountForDedupe !== null) {
                $data['reminder_count'] = (string) $reminderCountForDedupe;
            }

            // Always attempt FCM for create/next-stage; reminders require unique dedupe.
            $shouldNotify = $inserted !== 0 || $reminderCountForDedupe === null;
            if (! $shouldNotify) {
                return false;
            }

            if ($inserted !== 0) {
                AppNotification::query()->create([
                    'user_id' => $user->id,
                    'order_id' => null,
                    'type' => $wireType,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);
            }

            $fcmResult = $this->sendFcm(
                user: $user,
                title: $title,
                body: $body,
                data: $data,
                android: [
                    'notification' => [
                        'channel_id' => 'paramgold_critical_alerts_v5',
                        'notification_priority' => 'PRIORITY_MAX',
                        'default_vibrate_timings' => true,
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            );

            if ($requireFcmSuccess) {
                return ((int) ($fcmResult['success'] ?? 0)) > 0;
            }

            // Create/approve must not fail the business flow — treat as triggered even if
            // tokens are missing (logged inside sendFcm).
            return true;
        } catch (Throwable $e) {
            Log::warning('Payment approval critical notify failed: '.$e->getMessage(), [
                'user_id' => $user->id,
                'payment_request_id' => $paymentRequest->id,
                'type' => $wireType,
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function dispatchStatusUpdate(
        User $user,
        PaymentRequest $paymentRequest,
        string $type,
        string $title,
        string $body,
        array $extra = [],
    ): void {
        try {
            $inserted = DB::table('payment_request_push_dedupe')->insertOrIgnore([
                'payment_request_id' => $paymentRequest->id,
                'user_id' => $user->id,
                'type' => $type,
                'status_key' => (string) $paymentRequest->status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            $data = array_merge([
                'type' => $type,
                'payment_request_id' => (string) $paymentRequest->id,
                'request_no' => (string) $paymentRequest->request_no,
                'vendor_name' => (string) $paymentRequest->vendor_name,
                'amount' => (string) $paymentRequest->amount,
                'status' => (string) $paymentRequest->status,
                'status_label' => $paymentRequest->displayStatusLabel(),
                'route' => "/director/payment-requests/{$paymentRequest->id}",
                'action' => 'view_payment_request',
                'channel_id' => 'paramgold_critical_alerts_v5',
                'fullscreen' => '0',
            ], $extra);

            AppNotification::query()->create([
                'user_id' => $user->id,
                'order_id' => null,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

            $this->sendFcm(
                user: $user,
                title: $title,
                body: $body,
                data: $data,
            );
        } catch (Throwable $e) {
            Log::warning('Payment request push notify failed: '.$e->getMessage(), [
                'payment_request_id' => $paymentRequest->id,
                'type' => $type,
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $data
     * @param  array<string, mixed>  $android
     * @return array{success: int, failure: int, invalid_tokens: list<string>, details: list<array<string, mixed>>}
     */
    private function sendFcm(
        User $user,
        string $title,
        string $body,
        array $data,
        array $android = [],
    ): array {
        $tokens = DeviceToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            Log::warning('Payment push skipped: no device tokens', [
                'user_id' => $user->id,
                'type' => (string) ($data['type'] ?? ''),
                'payment_request_id' => (string) ($data['payment_request_id'] ?? ''),
            ]);

            return [
                'success' => 0,
                'failure' => 0,
                'invalid_tokens' => [],
                'details' => [],
            ];
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

        return $result;
    }

    /**
     * @return list<User>
     */
    private function adminUsers(): array
    {
        // LOGIN ROLE preferred. Legacy Filament Admin uses job_role=Admin
        // (seeded login role is often employee) — never designation text.
        return User::query()
            ->where(function ($query): void {
                $query->where('role', 'admin')
                    ->orWhere(function ($legacy): void {
                        $legacy->where('job_role', 'Admin')
                            ->where('role', '!=', 'director');
                    });
            })
            ->get()
            ->filter(fn (User $user): bool => (string) $user->role !== 'director')
            ->unique('id')
            ->values()
            ->all();
    }
}
