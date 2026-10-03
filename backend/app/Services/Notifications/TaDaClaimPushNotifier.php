<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Models\TaDaClaim;
use App\Models\User;
use Throwable;

final class TaDaClaimPushNotifier
{
    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifyDirectorsOfManagerSubmission(TaDaClaim $claim): void
    {
        $claim->loadMissing('employee:id,full_name');
        $name = $claim->employee?->full_name ?: 'Manager';
        $amount = $this->amount($claim);
        $title = 'New TA Bill';
        $body = "New TA Bill submitted by {$name}\nAmount: {$amount}";

        User::query()
            ->where('role', UserRole::Director->value)
            ->orderBy('id')
            ->each(function (User $director) use ($claim, $title, $body): void {
                $this->store(
                    $director,
                    'ta_da_manager_submitted',
                    $title,
                    $body,
                    '/director/ta-da-claims/'.$claim->id,
                    $claim,
                );
            });
    }

    public function notifyManagerOfDecision(TaDaClaim $claim, bool $approved): void
    {
        $claim->loadMissing('employee.user');
        $manager = $claim->employee?->user;
        if (! $manager instanceof User) {
            return;
        }

        $number = $claim->claimNumber();
        if ($approved) {
            $title = 'TA Bill approved';
            $body = "Your TA Bill {$number} has been approved.";
        } else {
            $reason = trim((string) $claim->admin_remark);
            $title = 'TA Bill rejected';
            $body = "Your TA Bill {$number} has been rejected.";
            if ($reason !== '') {
                $body .= "\nReason: {$reason}";
            }
        }

        $this->store(
            $manager,
            $approved ? 'ta_da_approved' : 'ta_da_rejected',
            $title,
            $body,
            '/manager/my-ta-da-claims/'.$claim->id,
            $claim,
        );
    }

    private function amount(TaDaClaim $claim): string
    {
        return '₹'.number_format((float) $claim->total_amount, 2, '.', ',');
    }

    private function store(
        User $user,
        string $type,
        string $title,
        string $body,
        string $route,
        TaDaClaim $claim,
    ): void {
        $data = [
            'type' => $type,
            'ta_da_claim_id' => (string) $claim->id,
            'claim_no' => $claim->claimNumber(),
            'route' => $route,
            'fullscreen' => '0',
        ];

        AppNotification::query()->create([
            'user_id' => $user->id,
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
            // The in-app notification is already stored.
        }
    }
}
