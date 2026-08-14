<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MarkPaymentRequestPaid
{
    public function execute(
        PaymentRequest $paymentRequest,
        User $actor,
        UploadedFile $proof,
        ?string $remark = null,
    ): PaymentRequest {
        if (! Gate::forUser($actor)->allows('markPaid', $paymentRequest)) {
            throw new AuthorizationException('You are not allowed to mark this payment as done.');
        }

        if (! $paymentRequest->canBeMarkedPaid()) {
            throw ValidationException::withMessages([
                'status' => ['Payment can only be marked done after both approvals.'],
            ]);
        }

        Validator::make(
            [
                'payment_proof' => $proof,
                'payment_remark' => $remark,
            ],
            [
                'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
                'payment_remark' => ['nullable', 'string', 'max:2000'],
            ],
        )->validate();

        return DB::transaction(function () use ($paymentRequest, $actor, $proof, $remark): PaymentRequest {
            $path = str_replace('\\', '/', $proof->store('payment-request-proofs', 'public'));

            $paymentRequest->markPaymentDone(
                actor: $actor,
                proofPath: $path,
                remark: $remark,
            );

            return $paymentRequest->fresh() ?? $paymentRequest;
        });
    }
}
