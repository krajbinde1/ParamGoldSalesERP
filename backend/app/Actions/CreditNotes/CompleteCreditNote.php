<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class CompleteCreditNote
{
    public function execute(CreditNote $creditNote, User $actor, ?string $remark = null): CreditNote
    {
        if (! Gate::forUser($actor)->allows('complete', $creditNote)) {
            throw new AuthorizationException('You are not authorized to complete this Credit Note.');
        }

        $creditNote->complete($actor->id, $remark);

        return $creditNote->fresh();
    }
}
