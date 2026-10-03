<?php

namespace App\Services\Dealers;

use App\Exceptions\CreditLimitExceededException;
use App\Models\Dealer;
use App\Models\DealerCreditLimit;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Holds a dealer credit lock for the whole order insert, then re-reads exposure.
 * Two orders that both fit the remaining limit before either commits cannot both pass.
 */
final class DealerCreditOrderGuard
{
    public function __construct(
        private readonly DealerCreditExposureService $exposure,
        private readonly DealerCreditLimitNotifier $notifier,
    ) {}

    public static function lockKey(int $dealerId): string
    {
        return 'dealer-credit-order:'.$dealerId;
    }

    public function place(Dealer $dealer, float $newOrderAmount, Closure $callback, ?int $excludeOrderId = null): mixed
    {
        $token = $this->acquire((int) $dealer->id);

        try {
            try {
                [$saved, $assessment] = DB::transaction(function () use ($dealer, $newOrderAmount, $callback, $excludeOrderId): array {
                    $locked = Dealer::query()->whereKey($dealer->id)->lockForUpdate()->firstOrFail();
                    DealerCreditLimit::query()->where('dealer_id', $locked->id)->lockForUpdate()->first();

                    $assessment = $this->exposure->assess($locked, $newOrderAmount, $excludeOrderId);

                    if ($assessment->blocksOrder()) {
                        throw new CreditLimitExceededException($assessment);
                    }

                    return [$callback(), $assessment];
                });
            } catch (CreditLimitExceededException $exception) {
                $this->notifier->syncThresholdWarning($dealer, $exception->assessment);

                throw new HttpResponseException($this->exceededResponse($exception->assessment));
            }

            $this->notifier->syncThresholdWarning($dealer, $assessment);

            return $saved;
        } finally {
            $this->release((int) $dealer->id, $token);
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    private function exceededResponse(DealerCreditAssessment $assessment)
    {
        return response()->json([
            'message' => 'Credit Limit Exceeded',
            'errors' => [
                'credit_limit' => ['Credit Limit Exceeded. Contact Manager for Limit Extension.'],
            ],
            'credit' => $assessment->toArray(),
        ], 422);
    }

    private function acquire(int $dealerId): string
    {
        $wait = max(0, (int) config('paramgold.credit_limit_lock_seconds', 10));
        $deadline = microtime(true) + $wait;
        $token = (string) Str::uuid();

        while (true) {
            if ($this->tryAcquire($dealerId, $token)) {
                return $token;
            }

            if (microtime(true) >= $deadline) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Another order is being checked against this dealer credit limit. Please retry.',
                    'errors' => [
                        'credit_limit' => ['Another order is being checked against this dealer credit limit. Please retry.'],
                    ],
                ], 423));
            }

            usleep(100000);
        }
    }

    private function tryAcquire(int $dealerId, string $token): bool
    {
        $until = now()->addSeconds(30);
        $inserted = DB::table('dealer_credit_order_locks')->insertOrIgnore([
            'dealer_id' => $dealerId,
            'owner' => $token,
            'locked_until' => $until,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return true;
        }

        return DB::table('dealer_credit_order_locks')
            ->where('dealer_id', $dealerId)
            ->where('locked_until', '<', now())
            ->update([
                'owner' => $token,
                'locked_until' => $until,
                'updated_at' => now(),
            ]) === 1;
    }

    private function release(int $dealerId, string $token): void
    {
        DB::table('dealer_credit_order_locks')
            ->where('dealer_id', $dealerId)
            ->where('owner', $token)
            ->delete();
    }
}
