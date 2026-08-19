<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaymentRequestSupportingDocumentController extends Controller
{
    public function show(
        Request $request,
        PaymentRequest $paymentRequest,
        PaymentRequestSupportingDocument $supportingDocument,
    ): BinaryFileResponse|StreamedResponse {
        $this->authorize('viewSupportingDocument', $paymentRequest);

        if ((int) $supportingDocument->payment_request_id !== (int) $paymentRequest->id) {
            abort(404);
        }

        if ($supportingDocument->trashed()) {
            abort(404);
        }

        $path = str_replace('\\', '/', (string) $supportingDocument->stored_file_path);
        if ($path === '' || str_contains($path, '..')) {
            throw new NotFoundHttpException('Supporting document not found.');
        }

        $absolute = $this->resolveExistingAbsolutePath($path);
        if ($absolute === null) {
            throw new NotFoundHttpException('Supporting document not found.');
        }

        $mime = $supportingDocument->resolvedMimeType();
        $downloadName = $supportingDocument->original_file_name ?: 'document';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
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

    /**
     * Resolve the file on the configured private disk, with a safe legacy fallback
     * for files stored under storage/app before the local disk root moved to private/.
     */
    private function resolveExistingAbsolutePath(string $relativePath): ?string
    {
        $disk = Storage::disk(PaymentRequestSupportingDocument::DISK);
        if ($disk->exists($relativePath)) {
            return $disk->path($relativePath);
        }

        $legacy = storage_path('app/'.$relativePath);
        $realLegacy = realpath($legacy);
        $allowedRoot = realpath(storage_path('app'));

        if (
            $realLegacy !== false
            && $allowedRoot !== false
            && str_starts_with($realLegacy, $allowedRoot)
            && is_file($realLegacy)
        ) {
            return $realLegacy;
        }

        return null;
    }

    private function asciiFallbackName(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]+/', '_', $name) ?: 'document';
        $fallback = str_replace(['/', '\\'], '_', $fallback);

        return $fallback !== '' ? $fallback : 'document';
    }
}
