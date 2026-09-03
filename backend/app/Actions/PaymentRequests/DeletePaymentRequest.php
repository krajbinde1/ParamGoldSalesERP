<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final class DeletePaymentRequest
{
    public function execute(PaymentRequest $paymentRequest, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('delete', $paymentRequest)) {
            throw new AuthorizationException('Payment Request cannot be deleted after first approval.');
        }

        DB::transaction(function () use ($paymentRequest): void {
            $documents = $paymentRequest->supportingDocuments()->withTrashed()->get();

            foreach ($documents as $document) {
                $path = $document->stored_file_path;
                if (is_string($path) && $path !== '' && Storage::disk(PaymentRequestSupportingDocument::DISK)->exists($path)) {
                    Storage::disk(PaymentRequestSupportingDocument::DISK)->delete($path);
                }

                $document->forceDelete();
            }

            $directory = 'payment-request-supporting/'.$paymentRequest->id;
            if (Storage::disk(PaymentRequestSupportingDocument::DISK)->exists($directory)) {
                Storage::disk(PaymentRequestSupportingDocument::DISK)->deleteDirectory($directory);
            }

            $paymentRequest->forceDelete();
        });
    }
}
