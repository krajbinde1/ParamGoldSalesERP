<?php

namespace App\Actions\DealerApplications;

use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreDealerApplicationDocument
{
    public function execute(
        DealerApplication $application,
        User $actor,
        string $documentType,
        UploadedFile $file,
    ): DealerApplicationDocument {
        if (! array_key_exists($documentType, DealerApplicationDocument::TYPE_LABELS)) {
            throw ValidationException::withMessages([
                'document_type' => 'Invalid document type.',
            ]);
        }

        if (! $application->isEditableByEmployee()) {
            throw ValidationException::withMessages([
                'status' => 'Documents can only be uploaded while the application is a draft or returned for correction.',
            ]);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, DealerApplicationDocument::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'Only PDF, JPG, JPEG and PNG files are allowed.',
            ]);
        }

        $directory = 'dealer-applications/'.$application->id;
        $filename = $documentType.'_'.Str::lower(Str::random(8)).'.'.$extension;
        $path = $file->storeAs($directory, $filename, DealerApplicationDocument::DISK);

        $existing = DealerApplicationDocument::query()
            ->where('dealer_application_id', $application->id)
            ->where('document_type', $documentType)
            ->first();

        if ($existing !== null) {
            $this->deleteStoredFile((string) $existing->file_path);
            $existing->update([
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
            ]);

            return $existing->fresh('uploadedByUser') ?? $existing;
        }

        return DealerApplicationDocument::query()->create([
            'dealer_application_id' => $application->id,
            'document_type' => $documentType,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ])->load('uploadedByUser');
    }

    private function deleteStoredFile(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_contains($normalized, '..')) {
            return;
        }

        if (Storage::disk(DealerApplicationDocument::DISK)->exists($normalized)) {
            Storage::disk(DealerApplicationDocument::DISK)->delete($normalized);
        }
    }
}
