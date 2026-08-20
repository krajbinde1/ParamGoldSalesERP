<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DealerApplicationDocumentController extends Controller
{
    public function show(
        Request $request,
        DealerApplication $dealerApplication,
        DealerApplicationDocument $dealerApplicationDocument,
    ): BinaryFileResponse {
        $this->authorize('viewDocument', $dealerApplication);

        $document = $dealerApplicationDocument;

        if ((int) $document->dealer_application_id !== (int) $dealerApplication->id) {
            abort(404);
        }

        if ($document->trashed()) {
            abort(404);
        }

        $path = str_replace('\\', '/', (string) $document->file_path);
        if ($path === '' || str_contains($path, '..')) {
            throw new NotFoundHttpException('Document not found.');
        }

        $absolute = $this->resolveExistingAbsolutePath($path);
        if ($absolute === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $downloadName = $document->original_filename ?: 'document';
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $downloadName,
            $this->asciiFallbackName($downloadName),
        );

        $mime = $document->mime_type ?: 'application/octet-stream';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function resolveExistingAbsolutePath(string $relativePath): ?string
    {
        $disk = Storage::disk(DealerApplicationDocument::DISK);
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
