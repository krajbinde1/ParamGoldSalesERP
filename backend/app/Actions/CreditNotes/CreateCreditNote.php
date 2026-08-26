<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\User;
use App\Services\CreditNotes\CreditNoteLineCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCreditNote
{
    public function __construct(
        private readonly CreditNoteLineCalculator $calculator = new CreditNoteLineCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        User $employeeUser,
        Dealer $dealer,
        array $payload,
        ?UploadedFile $document = null,
    ): CreditNote {
        $employee = $employeeUser->employee;

        if ($employee === null) {
            throw ValidationException::withMessages([
                'sales_employee_id' => ['Sales employee account is required.'],
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
        $documentPath = $document === null
            ? null
            : str_replace('\\', '/', $document->store('credit-note-docs', 'public'));

        return DB::transaction(function () use ($payload, $employee, $dealer, $creditNoteDate, $calculated, $documentPath): CreditNote {
            $creditNote = CreditNote::query()->create([
                'type' => $payload['type'],
                'dealer_id' => $dealer->id,
                'sales_employee_id' => $employee->id,
                'bill_reference' => $payload['bill_reference'],
                'credit_note_date' => $creditNoteDate->toDateString(),
                'amount' => $calculated['amount'],
                'remarks' => $payload['remarks'] ?? null,
                'supporting_document_path' => $documentPath,
                'status' => CreditNote::STATUS_PENDING_APPROVAL,
            ]);

            foreach ($calculated['items'] as $item) {
                $creditNote->items()->create($item);
            }

            return $creditNote->fresh(['items']);
        });
    }
}
