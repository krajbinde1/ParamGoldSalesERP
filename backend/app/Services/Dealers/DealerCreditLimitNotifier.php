<?php

namespace App\Services\Dealers;

use App\Models\AppNotification;
use App\Models\Dealer;
use App\Models\DealerCreditWarningNotice;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Notifications\FcmHttpClient;
use Illuminate\Database\QueryException;
use Throwable;

final class DealerCreditLimitNotifier
{
    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifySalesEmployee(Dealer $dealer, string $type, string $title, string $body): void
    {
        $user = $this->salesUser($dealer);
        if ($user === null) {
            return;
        }

        $this->store($user, $dealer, $type, $title, $body);
    }

    public function syncThresholdWarning(Dealer $dealer, DealerCreditAssessment $assessment): void
    {
        $user = $this->salesUser($dealer);
        if ($user === null) {
            return;
        }

        $warning = in_array($assessment->status, [
            DealerCreditAssessment::STATUS_NEAR_LIMIT,
            DealerCreditAssessment::STATUS_LIMIT_REACHED,
            DealerCreditAssessment::STATUS_ORDER_EXCEEDS,
        ], true);

        $existing = DealerCreditWarningNotice::query()
            ->where('dealer_id', $dealer->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $warning) {
            $existing?->delete();

            return;
        }

        if ($existing !== null && $existing->status === $assessment->status) {
            return;
        }

        try {
            DealerCreditWarningNotice::query()->updateOrCreate(
                [
                    'dealer_id' => $dealer->id,
                    'user_id' => $user->id,
                ],
                ['status' => $assessment->status],
            );
        } catch (QueryException) {
            return;
        }

        $this->store(
            $user,
            $dealer,
            'credit_limit_warning',
            'Credit limit warning',
            $this->warningBody($dealer, $assessment),
        );
    }

    private function warningBody(Dealer $dealer, DealerCreditAssessment $assessment): string
    {
        $name = (string) $dealer->firm_name;

        return match ($assessment->status) {
            DealerCreditAssessment::STATUS_ORDER_EXCEEDS => 'An order for '.$name.' would exceed the credit limit.',
            DealerCreditAssessment::STATUS_LIMIT_REACHED => $name.' has reached the credit limit.',
            default => $name.' is near the credit limit.',
        };
    }

    private function salesUser(Dealer $dealer): ?User
    {
        $dealer->loadMissing('assignedEmployee.user');
        $user = $dealer->assignedEmployee?->user;

        return $user instanceof User ? $user : null;
    }

    private function store(User $user, Dealer $dealer, string $type, string $title, string $body): void
    {
        $data = [
            'type' => $type,
            'dealer_id' => (string) $dealer->id,
            'dealer_name' => (string) $dealer->firm_name,
            'route' => '/dealers/'.$dealer->id.'/ledger',
            'fullscreen' => '0',
        ];

        AppNotification::query()->create([
            'user_id' => $user->id,
            'order_id' => null,
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

        try {
            $this->fcm->sendToTokens(
                tokens: $tokens,
                notification: [
                    'title' => $title,
                    'body' => $body,
                ],
                data: $data,
            );
        } catch (Throwable) {
            // In-app notification is already stored. Push delivery must not undo the credit change.
        }
    }
}
