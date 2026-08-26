<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreditNotes\CreateCreditNote;
use App\Actions\CreditNotes\UpdatePendingCreditNote;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Services\Dealers\DealerAccessService;
use App\Support\CreditNotes\CreditNoteDetailPresenter;
use App\Support\CreditNotes\CreditNotePayloadValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeCreditNoteController extends Controller
{
    public function __construct(
        private readonly CreditNoteDetailPresenter $presenter,
        private readonly CreditNotePayloadValidator $validator,
        private readonly DealerAccessService $dealerAccess,
        private readonly CreateCreditNote $createCreditNote,
        private readonly UpdatePendingCreditNote $updatePendingCreditNote,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $notes = CreditNote::query()->where('sales_employee_id', $employee->id);

        if ($request->filled('filter')) {
            $filter = (string) $request->query('filter');

            return response()->json([
                'credit_notes' => $this->applyFilter($notes, $filter)
                    ->with([
                        'dealer:id,firm_name,dealer_code',
                        'salesEmployee:id,full_name,employee_code',
                    ])
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (CreditNote $note): array => $this->presenter->presentListItem($note))
                    ->values(),
            ]);
        }

        $summary = [
            'total' => (clone $notes)->count(),
            'pending_approval' => (clone $notes)->where('status', CreditNote::STATUS_PENDING_APPROVAL)->count(),
            'approved' => (clone $notes)->where('status', CreditNote::STATUS_APPROVED)->count(),
            'completed' => (clone $notes)->where('status', CreditNote::STATUS_COMPLETED)->count(),
            'rejected' => (clone $notes)->where('status', CreditNote::STATUS_REJECTED)->count(),
        ];

        $recent = (clone $notes)
            ->with([
                'dealer:id,firm_name,dealer_code',
                'salesEmployee:id,full_name,employee_code',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (CreditNote $note): array => $this->presenter->presentListItem($note))
            ->values();

        return response()->json([
            'summary' => $summary,
            'recent_credit_notes' => $recent,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CreditNote::class);

        $validated = $this->validator->validate($request);
        $dealer = $this->resolveDealer($request, (int) $validated['dealer_id']);

        $creditNote = $this->createCreditNote->execute(
            employeeUser: $request->user(),
            dealer: $dealer,
            payload: $validated,
            document: $request->file('supporting_document'),
        );

        return response()->json([
            'message' => 'Credit Note submitted successfully.',
            'credit_note_id' => $creditNote->id,
            'credit_note_no' => $creditNote->credit_note_no,
            'status' => $creditNote->status,
            'amount' => (float) $creditNote->amount,
            'data' => $this->presenter->present($creditNote),
        ], 201);
    }

    public function show(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('view', $creditNote);

        return response()->json([
            'data' => $this->presenter->present($creditNote),
        ]);
    }

    public function update(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('update', $creditNote);

        $validated = $this->validator->validate($request);
        $dealer = $this->resolveDealer($request, (int) $validated['dealer_id']);

        $creditNote = $this->updatePendingCreditNote->execute(
            creditNote: $creditNote,
            dealer: $dealer,
            payload: $validated,
            editor: $request->user(),
            editedByRole: CreditNote::EDITED_BY_ROLE_SALES_EMPLOYEE,
            document: $request->file('supporting_document'),
        );

        return response()->json([
            'message' => 'Credit Note updated successfully.',
            'credit_note_id' => $creditNote->id,
            'credit_note_no' => $creditNote->credit_note_no,
            'status' => $creditNote->status,
            'amount' => (float) $creditNote->amount,
            'data' => $this->presenter->present($creditNote),
        ]);
    }

    private function resolveDealer(Request $request, int $dealerId)
    {
        $dealer = $this->dealerAccess->resolveAccessibleActiveDealer($request->user(), $dealerId);

        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        return $dealer;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CreditNote>  $query
     * @return \Illuminate\Database\Eloquent\Builder<CreditNote>
     */
    private function applyFilter($query, string $filter)
    {
        return match ($filter) {
            'pending', 'pending_approval' => $query->where('status', CreditNote::STATUS_PENDING_APPROVAL),
            'approved' => $query->where('status', CreditNote::STATUS_APPROVED),
            'completed' => $query->where('status', CreditNote::STATUS_COMPLETED),
            'rejected' => $query->where('status', CreditNote::STATUS_REJECTED),
            default => $query,
        };
    }
}
