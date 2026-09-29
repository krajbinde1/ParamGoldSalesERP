<?php

namespace App\Actions\CreditNotes;

use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\User;
use App\Services\CreditNotes\CreditNoteLineCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $startedAt = microtime(true);
        $employee = $employeeUser->employee;

        if ($employee === null) {
            throw ValidationException::withMessages([
                'sales_employee_id' => ['Sales employee account is required.'],
            ]);
        }

        $clientRequestId = $this->normalizeClientRequestId($payload['client_request_id'] ?? null);
        if ($clientRequestId !== null) {
            $existing = $this->findByClientRequestId($clientRequestId, (int) $employee->id);
            if ($existing !== null) {
                $this->logStep('idempotent_hit', $startedAt, [
                    'client_request_id' => $clientRequestId,
                    'credit_note_id' => $existing->id,
                ]);

                return $existing;
            }
        }

        $creditNoteDate = CreditNote::businessToday();
        if (filled($payload['credit_note_date'] ?? null) && ($payload['type'] ?? '') === CreditNote::TYPE_RATE_DIFFERENCE) {
            $creditNoteDate = Carbon::parse($payload['credit_note_date'], CreditNote::businessToday()->timezoneName)
                ->startOfDay();

            if ($creditNoteDate->greaterThan(CreditNote::businessToday())) {
                throw ValidationException::withMessages([
                    'credit_note_date' => ['Credit Note date cannot be in the future.'],
                ]);
            }
        }

        $calcStarted = microtime(true);
        $calculated = $this->calculator->calculate((string) $payload['type'], $payload['items']);
        $this->logStep('calculate_lines', $calcStarted, [
            'item_count' => count($payload['items'] ?? []),
        ]);

        $destinationDealerId = ($payload['type'] ?? null) === CreditNote::TYPE_SALES_RETURN
            && ($payload['move_to'] ?? null) === CreditNote::MOVE_TO_DEALER
            ? (int) ($payload['destination_dealer_id'] ?? 0)
            : null;

        if ($destinationDealerId !== null && $destinationDealerId === (int) $dealer->id) {
            throw ValidationException::withMessages([
                'destination_dealer_id' => ['Destination dealer must be different from the returning dealer.'],
            ]);
        }

        // Store the file outside the DB transaction so slow disk I/O does not hold locks.
        $documentPath = null;
        if ($document !== null) {
            $storeStarted = microtime(true);
            $documentPath = str_replace('\\', '/', $document->store('credit-note-docs', 'public'));
            $this->logStep('store_document', $storeStarted, [
                'bytes' => $document->getSize() ?: null,
                'mime' => $document->getMimeType(),
            ]);
        }

        try {
            $creditNote = DB::transaction(function () use (
                $payload,
                $employee,
                $dealer,
                $creditNoteDate,
                $calculated,
                $documentPath,
                $destinationDealerId,
                $clientRequestId,
                $startedAt,
            ): CreditNote {
                $createStarted = microtime(true);
                $creditNote = CreditNote::query()->create([
                    'type' => $payload['type'],
                    'move_to' => ($payload['type'] ?? null) === CreditNote::TYPE_SALES_RETURN
                        ? ($payload['move_to'] ?? null)
                        : null,
                    'dealer_id' => $dealer->id,
                    'destination_dealer_id' => $destinationDealerId ?: null,
                    'sales_employee_id' => $employee->id,
                    'client_request_id' => $clientRequestId,
                    'bill_reference' => $payload['bill_reference'],
                    'credit_note_date' => $creditNoteDate->toDateString(),
                    'amount' => $calculated['amount'],
                    'remarks' => $payload['remarks'] ?? null,
                    'supporting_document_path' => $documentPath,
                    'status' => CreditNote::STATUS_PENDING_APPROVAL,
                ]);
                $this->logStep('create_credit_note_row', $createStarted, [
                    'credit_note_id' => $creditNote->id,
                ]);

                $itemsStarted = microtime(true);
                foreach ($calculated['items'] as $item) {
                    $creditNote->items()->create($item);
                }
                $this->logStep('create_items', $itemsStarted, [
                    'item_count' => count($calculated['items']),
                ]);

                if ($creditNote->isMoveToDealer()) {
                    $orderStarted = microtime(true);
                    app(\App\Services\CreditNotes\SalesReturnTransferOrderService::class)
                        ->syncLinkedOrder($creditNote->fresh(['items', 'dealer', 'destinationDealer']) ?? $creditNote);
                    $this->logStep('sync_linked_orders', $orderStarted, [
                        'credit_note_id' => $creditNote->id,
                    ]);
                }

                return $creditNote->fresh(['items', 'destinationDealer', 'linkedOrder', 'linkedSourceOrder'])
                    ?? $creditNote;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($clientRequestId === null) {
                throw $exception;
            }

            $existing = $this->findByClientRequestId($clientRequestId, (int) $employee->id);
            if ($existing === null) {
                throw $exception;
            }

            $this->logStep('idempotent_race', $startedAt, [
                'client_request_id' => $clientRequestId,
                'credit_note_id' => $existing->id,
            ]);

            return $existing;
        }

        $this->logStep('create_complete', $startedAt, [
            'credit_note_id' => $creditNote->id,
            'has_document' => filled($documentPath),
            'move_to' => $creditNote->move_to,
        ]);

        return $creditNote;
    }

    private function normalizeClientRequestId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }

        return $value;
    }

    private function findByClientRequestId(string $clientRequestId, int $salesEmployeeId): ?CreditNote
    {
        return CreditNote::query()
            ->where('client_request_id', $clientRequestId)
            ->where('sales_employee_id', $salesEmployeeId)
            ->with(['items', 'destinationDealer', 'linkedOrder', 'linkedSourceOrder', 'dealer', 'salesEmployee'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logStep(string $step, float $startedAt, array $context = []): void
    {
        Log::info('credit_note.create.timing', array_merge([
            'step' => $step,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $context));
    }
}
