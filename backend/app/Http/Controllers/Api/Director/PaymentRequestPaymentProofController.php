<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestPaymentProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentRequestPaymentProofController extends Controller
{
    public function show(
        Request $request,
        PaymentRequest $paymentRequest,
        PaymentRequestPaymentProof $paymentProof,
    ): BinaryFileResponse {
        $this->authorize('view', $paymentRequest);

        if ((int) $paymentProof->payment_request_id !== (int) $paymentRequest->id) {
            abort(404);
        }

        $path = str_replace('\\', '/', (string) $paymentProof->stored_file_path);
        if ($path === '' || str_contains($path, '..')) {
            throw new NotFoundHttpException('Payment proof not found.');
        }

        $absolute = $this->resolveExistingAbsolutePath($path);
        if ($absolute === null) {
            throw new NotFoundHttpException('Payment proof not found.');
        }

        $mime = $paymentProof->resolvedMimeType();
        $downloadName = $paymentProof->original_file_name ?: 'payment-proof';
        $disposition = HeaderUtils::makeDisposition(
            $request->boolean('download')
                ? HeaderUtils::DISPOSITION_ATTACHMENT
                : HeaderUtils::DISPOSITION_INLINE,
            $downloadName,
            $this->asciiFallbackName($downloadName),
        );

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function resolveExistingAbsolutePath(string $relativePath): ?string
    {
        $disk = Storage::disk(PaymentRequestPaymentProof::DISK);
        if ($disk->exists($relativePath)) {
            return $disk->path($relativePath);
        }

        return null;
    }

    private function asciiFallbackName(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]+/', '_', $name) ?: 'payment-proof';
        $fallback = str_replace(['/', '\\'], '_', $fallback);

        return $fallback !== '' ? $fallback : 'payment-proof';
    }
}
