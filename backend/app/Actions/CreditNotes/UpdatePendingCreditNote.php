<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\User;
use App\Services\CreditNotes\CreditNoteLineCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class UpdatePendingCreditNote
{
    public function __construct(
        private readonly CreditNoteLineCalculator $calculator = new CreditNoteLineCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        CreditNote $creditNote,
        Dealer $dealer,
        array $payload,
        User $editor,
        string $editedByRole,
        ?UploadedFile $document = null,
    ): CreditNote {
        if (! $creditNote->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ['Only Credit Notes pending approval can be edited.'],
            ]);
        }

        $creditNoteDate = Carbon::parse($payload['credit_note_date'], CreditNote::businessToday()->timezoneName)
            ->startOfDay();

        if ($creditNoteDate->greaterThan(CreditNote::businessToday())) {
            throw ValidationException::withMessages([
                'credit_note_date' => ['Credit Note date cannot be in the future.'],
            ]);
        }

        $calculated = $this->calculator->calculate((string) $payload['type'], $payload['items']);

        $documentPath = $creditNote->supporting_document_path;
        if ($document !== null) {
            if (filled($documentPath)) {
                Storage::disk('public')->delete($documentPath);
            }
            $documentPath = str_replace('\\', '/', $document->store('credit-note-docs', 'public'));
        }

        $creditNote->items()->delete();

        $creditNote->update([
            'type' => $payload['type'],
            'dealer_id' => $dealer->id,
            'bill_reference' => $payload['bill_reference'],
            'credit_note_date' => $creditNoteDate->toDateString(),
            'amount' => $calculated['amount'],
            'remarks' => $payload['remarks'] ?? null,
            'supporting_document_path' => $documentPath,
            'last_edited_by' => $editor->id,
            'last_edited_at' => Carbon::now(CreditNote::businessToday()->timezoneName),
            'last_edited_by_role' => $editedByRole,
        ]);

        foreach ($calculated['items'] as $item) {
            $creditNote->items()->create($item);
        }

        return $creditNote->fresh(['items']);
    }
}
