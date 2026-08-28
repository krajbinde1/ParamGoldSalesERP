<?php

namespace App\Actions\Collections;

use App\Models\Collection;
use App\Models\CollectionAudit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class UpdateCollectionStatus
{
    public function execute(Collection $collection, string $status, User $actor): Collection
    {
        if (! in_array($status, Collection::adminEditableStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose Received, Not Received, or Rejected.',
            ]);
        }

        $collection->refresh();

        if ($collection->status === $status) {
            return $collection;
        }

        $old = CollectionAudit::snapshot($collection);
        $collection->update(['status' => $status]);
        CollectionAudit::record($collection->fresh() ?? $collection, $old, $actor);

        return $collection->fresh() ?? $collection;
    }
}
