<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AdminRejectPaymentRequest
{
    public function execute(PaymentRequest $paymentRequest, User $actor, string $reason): PaymentRequest
    {
        if (! Gate::forUser($actor)->allows('reject', $paymentRequest)) {
            throw new AuthorizationException('You are not allowed to reject this payment request.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Rejection reason is required (minimum 3 characters).'],
            ]);
        }

        $paymentRequest->rejectByAdmin($actor, $reason);

        return $paymentRequest->fresh() ?? $paymentRequest;
    }
}