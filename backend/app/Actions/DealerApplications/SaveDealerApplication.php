<?php

namespace App\Actions\DealerApplications;

use App\Models\DealerApplication;
use App\Models\DealerApplicationEvent;
use App\Models\User;
use App\Services\Dealers\DealerApplicationDuplicateChecker;
use Illuminate\Validation\ValidationException;

class SaveDealerApplication
{
    public function __construct(
        private readonly DealerApplicationDuplicateChecker $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, array $data, ?DealerApplication $application = null): DealerApplication
    {
        $employeeId = $actor->employee_id;
        if ($employeeId === null) {
            throw ValidationException::withMessages([
                'employee' => 'Only sales employees can create dealer applications.',
            ]);
        }

        if ($application !== null && (int) $application->employee_id !== (int) $employeeId) {
            throw ValidationException::withMessages([
                'employee' => 'You can only update your own dealer applications.',
            ]);
        }

        if ($application !== null && ! $application->isEditableByEmployee()) {
            throw ValidationException::withMessages([
                'status' => 'This dealer application can no longer be edited.',
            ]);
        }

        $payload = [
            'firm_name' => trim((string) $data['firm_name']),
            'owner_name' => trim((string) $data['owner_name']),
            'mobile' => trim((string) $data['mobile']),
            'gst_no' => filled($data['gst_no'] ?? null) ? strtoupper(trim((string) $data['gst_no'])) : null,
            'state' => trim((string) $data['state']),
            'district' => trim((string) $data['district']),
            'taluka' => trim((string) $data['taluka']),
            'village' => trim((string) $data['village']),
            'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];

        if ($application === null) {
            $application = DealerApplication::query()->create([
                ...$payload,
                'employee_id' => $employeeId,
                'status' => DealerApplication::STATUS_DRAFT,
            ]);
            $application->recordEvent(DealerApplicationEvent::CREATED, $actor);
        } else {
            $application->update($payload);
        }

        $matches = $this->duplicates->matches($application->fresh());
        $application->update(['duplicate_warning' => $matches !== []]);

        return $application->fresh(['employee', 'documents.uploadedByUser', 'events', 'dealer'])
            ?? $application;
    }
}
