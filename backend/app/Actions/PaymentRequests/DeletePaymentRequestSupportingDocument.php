<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequestSupportingDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class DeletePaymentRequestSupportingDocument
{
    public function execute(PaymentRequestSupportingDocument $document, User $actor): void
    {
        $paymentRequest = $document->paymentRequest;
        if ($paymentRequest === null) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['Payment Request cannot be modified after Director approval.'],
            ]);
        }

        if ($paymentRequest->isLockedForAdminEdits()) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['Payment Request cannot be modified after Director approval.'],
            ]);
        }

        if (! $actor->can('manageSupportingDocuments', $paymentRequest)) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['Payment Request cannot be modified after Director approval.'],
            ]);
        }

        $path = $document->stored_file_path;
        $document->update(['deleted_by' => $actor->id]);
        $document->delete();

        if (is_string($path) && $path !== '' && Storage::disk(PaymentRequestSupportingDocument::DISK)->exists($path)) {
            Storage::disk(PaymentRequestSupportingDocument::DISK)->delete($path);
        }
    }
}
