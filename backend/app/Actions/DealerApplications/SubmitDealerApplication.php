<?php

namespace App\Actions\DealerApplications;

use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Models\DealerApplicationEvent;
use App\Models\User;
use App\Services\Dealers\DealerApplicationDuplicateChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitDealerApplication
{
    public function __construct(
        private readonly DealerApplicationDuplicateChecker $duplicates,
    ) {}

    public function execute(DealerApplication $application, User $actor): DealerApplication
    {
        if ((int) $application->employee_id !== (int) $actor->employee_id) {
            throw ValidationException::withMessages([
                'employee' => 'You can only submit your own dealer applications.',
            ]);
        }

        if (! $application->canSubmit()) {
            throw ValidationException::withMessages([
                'status' => 'This dealer application cannot be submitted in its current status.',
            ]);
        }

        if ($application->latitude === null || $application->longitude === null) {
            throw ValidationException::withMessages([
                'location' => 'Please capture dealer shop location before submitting.',
            ]);
        }

        $missing = $application->missingDocumentTypes();
        if ($missing !== []) {
            $labels = array_map(
                fn (string $type): string => DealerApplicationDocument::TYPE_LABELS[$type] ?? $type,
                $missing,
            );

            throw ValidationException::withMessages([
                'documents' => 'Upload all required dealer documents before submitting: '.implode(', ', $labels).'.',
            ]);
        }

        $wasCorrection = $application->status === DealerApplication::STATUS_CORRECTION_REQUIRED;
        $nextStatus = $wasCorrection
            ? $application->nextStatusAfterResubmit()
            : DealerApplication::STATUS_PENDING_MANAGER;

        return DB::transaction(function () use ($application, $actor, $wasCorrection, $nextStatus): DealerApplication {
            /** @var DealerApplication $locked */
            $locked = DealerApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canSubmit()) {
                throw ValidationException::withMessages([
                    'status' => 'This dealer application cannot be submitted in its current status.',
                ]);
            }

            $matches = $this->duplicates->matches($locked);

            $locked->update([
                'status' => $nextStatus,
                'submitted_at' => $locked->submitted_at ?? now(),
                'duplicate_warning' => $matches !== [],
                'last_action' => $wasCorrection ? DealerApplicationEvent::RESUBMITTED : DealerApplicationEvent::SUBMITTED,
                'last_action_by' => $actor->id,
                'last_action_by_name' => $actor->name,
                'last_action_at' => now(),
                'last_action_remark' => null,
            ]);

            $locked->recordEvent(
                $wasCorrection ? DealerApplicationEvent::RESUBMITTED : DealerApplicationEvent::SUBMITTED,
                $actor,
                null,
                $matches === [] ? [] : ['duplicate_matches' => $matches],
            );

            return $locked->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
                ?? $locked;
        });
    }
}
