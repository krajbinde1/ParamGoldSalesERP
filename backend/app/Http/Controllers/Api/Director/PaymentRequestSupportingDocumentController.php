<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentRequestSupportingDocumentController extends Controller
{
    public function show(
        Request $request,
        PaymentRequest $paymentRequest,
        PaymentRequestSupportingDocument $supportingDocument,
    ): StreamedResponse {
        $this->authorize('viewSupportingDocument', $paymentRequest);

        if ((int) $supportingDocument->payment_request_id !== (int) $paymentRequest->id) {
            abort(404);
        }

        if ($supportingDocument->trashed()) {
            abort(404);
        }

        $path = (string) $supportingDocument->stored_file_path;
        if ($path === '' || str_contains($path, '..') || ! Storage::disk(PaymentRequestSupportingDocument::DISK)->exists($path)) {
            throw new NotFoundHttpException('Supporting document not found.');
        }

        $mime = $supportingDocument->mime_type ?: 'application/octet-stream';
        $downloadName = $supportingDocument->original_file_name ?: 'document';

        return Storage::disk(PaymentRequestSupportingDocument::DISK)->response(
            $path,
            $downloadName,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($downloadName).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            ]
        );
    }
}
