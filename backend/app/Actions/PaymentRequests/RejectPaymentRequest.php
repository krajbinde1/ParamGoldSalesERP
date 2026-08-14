<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RejectPaymentRequest
{
    public function execute(PaymentRequest $paymentRequest, User $actor, string $remark): PaymentRequest
    {
        $remark = trim($remark);
        if (mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'remark' => ['Rejection remark is required (minimum 3 characters).'],
            ]);
        }

        if ($paymentRequest->canBeFirstApproved()) {
            if (! Gate::forUser($actor)->allows('rejectFirst', $paymentRequest)) {
                throw new AuthorizationException('You are not allowed to reject at first approval.');
            }

            $paymentRequest->rejectFirst(
                $actor,
                (string) config('payment_requests.first_approver_display_role', 'Director'),
                $remark,
            );

            return $paymentRequest->fresh() ?? $paymentRequest;
        }

        if ($paymentRequest->canBeSecondApproved()) {
            if (! Gate::forUser($actor)->allows('rejectSecond', $paymentRequest)) {
                throw new AuthorizationException('You are not allowed to reject at second approval.');
            }

            $paymentRequest->rejectSecond(
                $actor,
                (string) config('payment_requests.second_approver_display_role', 'Director'),
                $remark,
            );

            return $paymentRequest->fresh() ?? $paymentRequest;
        }

        throw new AuthorizationException('This payment request cannot be rejected in its current status.');
    }
}
