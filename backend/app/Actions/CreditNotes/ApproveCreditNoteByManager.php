<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\User;
use App\Services\CreditNotes\SalesReturnTransferOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class ApproveCreditNoteByManager
{
    public function execute(CreditNote $creditNote, User $actor, ?string $remark = null): CreditNote
    {
        if (! Gate::forUser($actor)->allows('approve', $creditNote)) {
            throw new AuthorizationException('You are not authorized to approve this Credit Note.');
        }

        $creditNote->approve($actor->id, $remark);
        $fresh = $creditNote->fresh() ?? $creditNote;

        if ($fresh->isMoveToDealer()) {
            app(SalesReturnTransferOrderService::class)->sendApprovedTransferToBilling($fresh, $actor->id);
        }

        return $fresh->fresh() ?? $fresh;
    }
}
