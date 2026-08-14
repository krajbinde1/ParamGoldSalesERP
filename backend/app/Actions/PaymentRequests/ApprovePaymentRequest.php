<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ApprovePaymentRequest
{
    public function execute(PaymentRequest $paymentRequest, User $actor): PaymentRequest
    {
        if ($paymentRequest->canBeFirstApproved()) {
            if (! Gate::forUser($actor)->allows('approveFirst', $paymentRequest)) {
                throw new AuthorizationException('You are not allowed to perform first approval.');
            }

            $paymentRequest->approveFirst(
                $actor,
                (string) config('payment_requests.first_approver_display_role', 'Director'),
            );

            return $paymentRequest->fresh() ?? $paymentRequest;
        }

        if ($paymentRequest->canBeSecondApproved()) {
            if (! Gate::forUser($actor)->allows('approveSecond', $paymentRequest)) {
                throw new AuthorizationException('You are not allowed to perform second approval.');
            }

            $paymentRequest->approveSecond(
                $actor,
                (string) config('payment_requests.second_approver_display_role', 'Director'),
            );

            return $paymentRequest->fresh() ?? $paymentRequest;
        }

        throw new AuthorizationException('This payment request cannot be approved in its current status.');
    }
}
