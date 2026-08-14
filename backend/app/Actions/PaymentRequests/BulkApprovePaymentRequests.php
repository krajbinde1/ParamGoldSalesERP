<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BulkApprovePaymentRequests
{
    public function __construct(
        private readonly ApprovePaymentRequest $approve = new ApprovePaymentRequest,
    ) {}

    /**
     * Approves each request via the individual approval action (policy + stage checks).
     *
     * @param  list<int>  $ids
     * @return array{approved: int, failed: int, errors: list<string>}
     */
    public function execute(User $actor, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $result = ['approved' => 0, 'failed' => 0, 'errors' => []];

        if ($ids === []) {
            return $result;
        }

        $requests = PaymentRequest::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        foreach ($requests as $paymentRequest) {
            try {
                $this->approve->execute($paymentRequest, $actor);
                $result['approved']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ($paymentRequest->request_no ?: '#'.$paymentRequest->id).': '.$e->getMessage();
                Log::warning('Bulk payment approval item failed: '.$e->getMessage(), [
                    'payment_request_id' => $paymentRequest->id,
                    'user_id' => $actor->id,
                ]);
            }
        }

        return $result;
    }
}
