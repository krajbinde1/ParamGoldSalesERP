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
    public const TYPE_CREATED = 'payment_request_created';

    public const TYPE_FIRST_APPROVED = 'payment_request_first_approved';

    public const TYPE_FINAL_APPROVED = 'payment_request_final_approved';

    public const TYPE_REJECTED_FIRST = 'payment_request_rejected_first';

    public const TYPE_REJECTED_SECOND = 'payment_request_rejected_second';

    public const TYPE_PAYMENT_DONE = 'payment_request_payment_done';

    public const TYPE_APPROVAL_REQUIRED = 'payment_approval_required';

    public const TYPE_REMINDER = 'payment_request_reminder';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
        private readonly PaymentRequestApproverResolver $approvers = new PaymentRequestApproverResolver,
    ) {}

    public function notifyCreated(PaymentRequest $paymentRequest): void
    {
        $user = $this->approvers->firstApprover();
        if (! $user) {
            return;
        }

        $this->notifyCurrentApproverQueue(
            user: $user,
            status: PaymentRequest::STATUS_PENDING_FIRST,
            type: self::TYPE_CREATED,
            title: 'Payment Approval Required',
            dedupePaymentRequest: $paymentRequest,
            isReminder: false,
        );
    }

    public function notifyFirstApproved(PaymentRequest $paymentRequest): void
    {
        $user = $this->approvers->secondApprover();
        if (! $user) {
            return;
        }

        $this->notifyCurrentApproverQueue(
            user: $user,
            status: PaymentRequest::STATUS_PENDING_SECOND,
            type: self::TYPE_FIRST_APPROVED,
            title: 'Payment Approval Required',
            dedupePaymentRequest: $paymentRequest,
            isReminder: false,
        );
    }

    /**
     * @param  Collection<int, PaymentRequest>  $requests
     */
    public function notifyReminder(Collection $requests): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        $status = (string) $requests->first()->status;
        $user = match ($status) {
            PaymentRequest::STATUS_PENDING_FIRST => $this->approvers->firstApprover(),
            PaymentRequest::STATUS_PENDING_SECOND => $this->approvers->secondApprover(),
            default => null,
        };

        if (! $user) {
            return;
        }

        $seed = $requests->sortByDesc('id')->first();

        $this->notifyCurrentApproverQueue(
            user: $user,
            status: $status,
            type: self::TYPE_REMINDER,
            title: 'Payment Approval Reminder',
            dedupePaymentRequest: $seed,
            isReminder: true,
            bodySuffix: ' still waiting for your approval.',
        );
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

    private function notifyCurrentApproverQueue(
        User $user,
        string $status,
        string $type,
        string $title,
        PaymentRequest $dedupePaymentRequest,
        bool $isReminder,
        string $bodySuffix = ' Pending',
    ): void {
        try {
            $pending = PaymentRequest::query()
                ->where('status', $status)
                ->orderBy('id')
                ->get();

            $count = $pending->count();
            if ($count === 0) {
                return;
            }

            $total = (float) $pending->sum(fn (PaymentRequest $pr): float => (float) $pr->amount);
            $amountLabel = '₹'.number_format($total, 0, '.', ',');
            $requestLabel = $count === 1 ? '1 Payment Request' : "{$count} Payment Requests";
            $body = "{$requestLabel} • {$amountLabel}{$bodySuffix}";

            $dedupeType = $isReminder
                ? self::TYPE_REMINDER.'_'.$dedupePaymentRequest->id.'_'.((int) $dedupePaymentRequest->reminder_count)
                : $type;
            $statusKey = $isReminder
                ? 'reminder_'.((int) $dedupePaymentRequest->reminder_count)
                : (string) $dedupePaymentRequest->status;

            $inserted = DB::table('payment_request_push_dedupe')->insertOrIgnore([
                'payment_request_id' => $dedupePaymentRequest->id,
                'user_id' => $user->id,
                'type' => $dedupeType,
                'status_key' => $statusKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0 && ! $isReminder) {
                // Still allow FCM summary refresh for a new request if inbox already logged.
            }

            if ($inserted !== 0 || $isReminder) {
                AppNotification::query()->create([
                    'user_id' => $user->id,
                    'order_id' => null,
                    'type' => $isReminder ? self::TYPE_REMINDER : self::TYPE_APPROVAL_REQUIRED,
                    'title' => $title,
                    'body' => $body,
                    'data' => [
                        'type' => $isReminder ? self::TYPE_REMINDER : self::TYPE_APPROVAL_REQUIRED,
                        'payment_request_id' => (string) $dedupePaymentRequest->id,
                        'pending_count' => (string) $count,
                        'pending_amount' => (string) $total,
                        'status' => $status,
                        'route' => '/director/payment-requests',
                        'action' => 'review',
                        'channel_id' => 'paramgold_approvals_v2',
                        'fullscreen' => '1',
                    ],
                ]);
            }

            $data = [
                'type' => $isReminder ? self::TYPE_REMINDER : self::TYPE_APPROVAL_REQUIRED,
                'payment_request_id' => (string) $dedupePaymentRequest->id,
                'pending_count' => (string) $count,
                'pending_amount' => (string) $total,
                'status' => $status,
                'route' => '/director/payment-requests',
                'action' => 'review',
                'channel_id' => 'paramgold_approvals_v2',
                'fullscreen' => '1',
            ];

            $this->sendFcm(
                user: $user,
                title: $title,
                body: $body,
                data: $data,
                android: [
                    'notification' => [
                        'channel_id' => 'paramgold_approvals_v2',
                        'notification_priority' => 'PRIORITY_MAX',
                        'default_vibrate_timings' => true,
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            );
        } catch (Throwable $e) {
            Log::warning('Payment approval queue notify failed: '.$e->getMessage(), [
                'user_id' => $user->id,
                'status' => $status,
                'type' => $type,
            ]);
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
                'channel_id' => 'paramgold_status_v2',
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
     */
    private function sendFcm(
        User $user,
        string $title,
        string $body,
        array $data,
        array $android = [],
    ): void {
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
    }

    /**
     * @return list<User>
     */
    private function adminUsers(): array
    {
        return User::query()
            ->where('job_role', 'Admin')
            ->get()
            ->filter(fn (User $user): bool => $user->isAdminUser() && ! $user->isDirectorUser())
            ->unique('id')
            ->values()
            ->all();
    }
}
