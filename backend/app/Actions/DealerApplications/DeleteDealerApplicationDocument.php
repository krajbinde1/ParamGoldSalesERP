<?php

namespace App\Actions\DealerApplications;

use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteDealerApplicationDocument
{
    public function execute(
        DealerApplication $application,
        DealerApplicationDocument $document,
        User $actor,
    ): DealerApplication {
        if ((int) $document->dealer_application_id !== (int) $application->id) {
            abort(404);
        }

        if ((int) $application->employee_id !== (int) $actor->employee_id) {
            throw ValidationException::withMessages([
                'employee' => 'You can only update your own dealer applications.',
            ]);
        }

        if (! $application->isEditableByEmployee()) {
            throw ValidationException::withMessages([
                'status' => 'Documents can only be removed while the application is a draft or returned for correction.',
            ]);
        }

        $path = str_replace('\\', '/', (string) $document->file_path);
        $document->forceDelete();

        if ($path !== '' && ! str_contains($path, '..') && Storage::disk(DealerApplicationDocument::DISK)->exists($path)) {
            Storage::disk(DealerApplicationDocument::DISK)->delete($path);
        }

        return $application->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
            ?? $application;
    }
}
