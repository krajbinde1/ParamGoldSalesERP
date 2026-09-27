<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\User;
use App\Services\CreditNotes\CreditNoteStockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ApproveCreditNoteByProduction
{
    public function execute(CreditNote $creditNote, User $actor, ?string $remark = null): CreditNote
    {
        if (! Gate::forUser($actor)->allows('approveAsProduction', $creditNote)) {
            throw new AuthorizationException('You are not authorized to approve this factory return.');
        }

        $creditNote->approveByProduction($actor->id, $remark);
        $fresh = $creditNote->fresh() ?? $creditNote;
        app(CreditNoteStockService::class)->postFactoryReturn($fresh, $actor);

        return $fresh->fresh() ?? $fresh;
    }
}
