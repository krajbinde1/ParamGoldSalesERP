<?php

namespace App\Actions\Collections;

use App\Models\Collection;
use App\Models\CollectionAudit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class UpdateCollectionStatus
{
    public function execute(Collection $collection, string $status, User $actor, ?string $remark = null): Collection
    {
        if (! in_array($status, Collection::adminEditableStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose Received, Not Received, or Rejected.',
            ]);
        }

        $remark = $this->normalizedRemark($remark);

        if (Collection::statusRequiresRemark($status) && blank($remark)) {
            throw ValidationException::withMessages([
                'admin_remark' => 'Remark is required for Not Received and Rejected.',
            ]);
        }

        $collection->refresh();

        if ($collection->status === $status) {
            return $collection;
        }

        $old = CollectionAudit::snapshot($collection);
        $payload = ['status' => $status];

        if (Collection::statusRequiresRemark($status)) {
            $payload['admin_remark'] = $remark;
        }

        $collection->update($payload);
        CollectionAudit::record($collection->fresh() ?? $collection, $old, $actor);

        return $collection->fresh() ?? $collection;
    }

    private function normalizedRemark(?string $remark): ?string
    {
        if ($remark === null) {
            return null;
        }

        $remark = trim($remark);

        return $remark === '' ? null : $remark;
    }
}
