<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RejectCreditNoteWithRemarks
{
    public function execute(CreditNote $creditNote, User $actor, string $remark, string $rejectedByRole): CreditNote
    {
        if (! Gate::forUser($actor)->allows('reject', $creditNote)) {
            throw new AuthorizationException('You are not authorized to reject this Credit Note.');
        }

        $remark = trim($remark);

        if ($remark === '') {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remarks are required.'],
            ]);
        }

        if (mb_strlen($remark) < 3) {
            throw ValidationException::withMessages([
                'rejection_remark' => ['Rejection remarks must be at least 3 characters.'],
            ]);
        }

        $creditNote->reject($actor->id, $remark, $rejectedByRole);

        return $creditNote->fresh();
    }
}
