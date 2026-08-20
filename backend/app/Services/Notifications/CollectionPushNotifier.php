<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Collection;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CollectionPushNotifier
{
    public const TYPE_CREATED = 'collection_created';

    public const TYPE_RECEIVED = 'collection_received';

    public function __construct(
        private readonly FcmHttpClient $fcm = new FcmHttpClient,
    ) {}

    public function notifyCreated(Collection $collection): void
    {
        $collection->loadMissing([
            'dealer:id,firm_name',
            'salesEmployee:id,full_name,reporting_manager_id',
        ]);

        $title = 'New Collection Added';
        $body = $this->createdBody($collection);

        $managerUser = $this->reportingManagerUser($collection);
        if ($managerUser) {
            $this->dispatchToUser(
                user: $managerUser,
                collection: $collection,
                type: self::TYPE_CREATED,
                statusKey: 'created',
                title: $title,
                body: $body,
                route: '/manager/collections/'.$collection->id,
            );
        }

        foreach ($this->activeDirectorUsers() as $director) {
            if ($managerUser && $director->id === $managerUser->id) {
                continue;
            }

            $this->dispatchToUser(
                user: $director,
                collection: $collection,
                type: self::TYPE_CREATED,
                statusKey: 'created',
                title: $title,
                body: $body,
                route: '/director/collections/'.$collection->id,
            );
        }
    }

    public function notifyReceived(Collection $collection): void
    {
        if ($collection->status !== Collection::STATUS_RECEIVED) {
            return;
        }

        $collection->loadMissing([
            'dealer:id,firm_name',
            'salesEmployee:id,full_name,reporting_manager_id',
            'salesEmployee.user',
        ]);

        $title = 'Collection Status Updated';
        $body = $this->receivedBody($collection);

        $employeeUser = $collection->salesEmployee?->user
            ?? User::query()->where('employee_id', $collection->sales_employee_id)->first();

        if ($employeeUser instanceof User) {
            $this->dispatchToUser(
                user: $employeeUser,
                collection: $collection,
                type: self::TYPE_RECEIVED,
                statusKey: Collection::STATUS_RECEIVED,
                title: $title,
                body: $body,
                route: '/collections/'.$collection->id,
            );
        }

        $managerUser = $this->reportingManagerUser($collection);
        if ($managerUser && (! $employeeUser instanceof User || $managerUser->id !== $employeeUser->id)) {
            $this->dispatchToUser(
                user: $managerUser,
                collection: $collection,
                type: self::TYPE_RECEIVED,
                statusKey: Collection::STATUS_RECEIVED,
                title: $title,
                body: $body,
                route: '/manager/collections/'.$collection->id,
            );
        }
    }

    private function createdBody(Collection $collection): string
    {
        $employee = $collection->salesEmployee?->full_name ?: '-';
        $dealer = $this->dealerName($collection);
        $amount = $this->formatAmount($collection);

        return "{$employee} • {$dealer} • {$amount}";
    }

    private function receivedBody(Collection $collection): string
    {
        $amount = $this->formatAmount($collection);
        $dealer = $this->dealerName($collection);

        return "{$amount} collection from {$dealer} has been marked as Received.";
    }

    private function dealerName(Collection $collection): string
    {
        return $collection->dealer?->firm_name ?: '-';
    }

    private function formatAmount(Collection $collection): string
    {
        $amount = (float) $collection->amount;
        $formatted = fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0, '.', ',')
            : number_format($amount, 2, '.', ',');

        return '₹'.$formatted;
    }

    private function reportingManagerUser(Collection $collection): ?User
    {
        $collection->loadMissing(['salesEmployee:id,reporting_manager_id']);
        $managerEmployeeId = $collection->salesEmployee?->reporting_manager_id;
        if (! $managerEmployeeId) {
            return null;
        }

        return User::query()
            ->where('employee_id', $managerEmployeeId)
            ->where('role', UserRole::Manager->value)
            ->first();
    }

    /**
     * @return list<User>
     */
    private function activeDirectorUsers(): array
    {
        return User::query()
            ->with('employee:id,status')
            ->where('role', UserRole::Director->value)
            ->get()
            ->filter(function (User $user): bool {
                if ($user->employee === null) {
                    return true;
                }

                return $user->employee->status === true;
            })
            ->unique('id')
            ->values()
            ->all();
    }

    private function dispatchToUser(
        User $user,
        Collection $collection,
        string $type,
        string $statusKey,
        string $title,
        string $body,
        string $route,
    ): void {
        try {
            $inserted = DB::table('collection_push_dedupe')->insertOrIgnore([
                'collection_id' => $collection->id,
                'user_id' => $user->id,
                'type' => $type,
                'status_key' => $statusKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            $data = [
                'type' => $type,
                'notification_type' => $type,
                'collection_id' => (string) $collection->id,
                'employee_id' => (string) ($collection->sales_employee_id ?? ''),
                'dealer_id' => (string) ($collection->dealer_id ?? ''),
                'dealer_name' => $this->dealerName($collection),
                'sales_person_name' => (string) ($collection->salesEmployee?->full_name ?? ''),
                'amount' => (string) $collection->amount,
                'status' => (string) $collection->status,
                'status_label' => Collection::statusLabels()[$collection->status] ?? (string) $collection->status,
                'event_at' => (string) ($collection->updated_at?->toIso8601String() ?? now()->toIso8601String()),
                'route' => $route,
                'action' => 'view',
                'channel_id' => FcmHttpClient::CHANNEL_CRITICAL,
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
                ->unique()
                ->filter()
                ->values()
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
            Log::warning('Collection push notify failed: '.$e->getMessage(), [
                'collection_id' => $collection->id,
                'type' => $type,
                'user_id' => $user->id,
            ]);
        }
    }
}
