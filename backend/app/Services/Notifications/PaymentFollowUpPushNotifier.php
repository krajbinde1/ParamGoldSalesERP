<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\PaymentFollowUpEntry;
use App\Models\User;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PaymentFollowUpPushNotifier
{
    public const TYPE_DUE = 'payment_follow_up_due';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifyDue(PaymentFollowUpEntry $entry, User $user): void
    {
        $entry->loadMissing(['dealer:id,firm_name', 'cycle']);

        $dealerName = $entry->dealer?->firm_name ?: 'Dealer';
        $outstanding = IndianCurrency::format((float) $entry->outstanding_at_time);
        $expected = $entry->expected_amount !== null
            ? IndianCurrency::format((float) $entry->expected_amount)
            : 'As discussed';

        $title = 'Payment Follow-up Due';
        $body = $dealerName.' • Outstanding: '.$outstanding.' • Expected: '.$expected;
        $route = '/payment-follow-ups/'.$entry->dealer_id;

        $data = [
            'type' => self::TYPE_DUE,
            'notification_type' => self::TYPE_DUE,
            'payment_follow_up_entry_id' => (string) $entry->id,
            'cycle_id' => (string) $entry->cycle_id,
            'dealer_id' => (string) $entry->dealer_id,
            'dealer_name' => $dealerName,
            'outstanding' => (string) $entry->outstanding_at_time,
            'expected_amount' => $entry->expected_amount !== null ? (string) $entry->expected_amount : '',
            'next_follow_up_date' => $entry->next_follow_up_date?->toDateString() ?? '',
            'event_at' => Carbon::now('Asia/Kolkata')->toIso8601String(),
            'route' => $route,
            'action' => 'view',
            'channel_id' => FcmHttpClient::CHANNEL_CRITICAL,
            'fullscreen' => '0',
        ];

        AppNotification::query()->create([
            'user_id' => $user->id,
            'order_id' => null,
            'type' => self::TYPE_DUE,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $tokens = DeviceToken::query()
            ->where('user_id', $user->id)
            ->pluck('token')
            ->unique()
            ->filter()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        try {
            $result = $this->fcm->sendToTokens(
                tokens: $tokens,
                notification: [
                    'title' => $title,
                    'body' => $body,
                ],
                data: $data,
                android: [
                    'notification' => [
                        'channel_id' => FcmHttpClient::CHANNEL_CRITICAL,
                        'notification_priority' => 'PRIORITY_HIGH',
                        'default_sound' => true,
                        'default_vibrate_timings' => true,
                        'sound' => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
            );

            if ($result['invalid_tokens'] !== []) {
                DeviceToken::query()
                    ->whereIn('token', $result['invalid_tokens'])
                    ->delete();
            }
        } catch (Throwable $e) {
            Log::warning('Payment follow-up push failed: '.$e->getMessage(), [
                'entry_id' => $entry->id,
                'user_id' => $user->id,
            ]);
        }
    }
}
