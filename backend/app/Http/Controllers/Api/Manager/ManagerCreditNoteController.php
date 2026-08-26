<?php

namespace App\Http\Controllers\Api\Manager;

use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Actions\CreditNotes\UpdatePendingCreditNote;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Services\CreditNotes\ManagerCreditNoteAccessService;
use App\Services\Dealers\DealerAccessService;
use App\Support\CreditNotes\CreditNoteDetailPresenter;
use App\Support\CreditNotes\CreditNotePayloadValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManagerCreditNoteController extends Controller
{
    public function __construct(
        private readonly ManagerCreditNoteAccessService $access,
        private readonly DealerAccessService $dealerAccess,
        private readonly CreditNoteDetailPresenter $presenter,
        private readonly CreditNotePayloadValidator $validator,
        private readonly UpdatePendingCreditNote $updatePendingCreditNote,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditNote::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending_approval,approved,completed,rejected'],
            'sales_employee_id' => ['nullable', 'integer'],
            'dealer' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:sales_return,rate_difference'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $teamQuery = $this->access->scopeToManagerTeam(CreditNote::query(), $request->user());
        $status = $validated['status'] ?? null;

        $notes = (clone $teamQuery)
            ->with([
                'dealer:id,dealer_code,firm_name,village,taluka,district,state',
                'salesEmployee:id,full_name,employee_code',
                'rejectedByUser:id,name',
            ])
            ->when(filled($status), fn ($q) => $q->where('status', $status))
            ->when(
                filled($validated['sales_employee_id'] ?? null),
                fn ($q) => $q->where('sales_employee_id', (int) $validated['sales_employee_id']),
            )
            ->when(filled($validated['type'] ?? null), fn ($q) => $q->where('type', $validated['type']))
            ->when(filled($validated['dealer'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['dealer'].'%';
                $q->whereHas('dealer', function ($dealerQuery) use ($term): void {
                    $dealerQuery->where('firm_name', 'like', $term)
                        ->orWhere('dealer_code', 'like', $term);
                });
            })
            ->when(
                filled($validated['date_from'] ?? null),
                fn ($q) => $q->whereDate('credit_note_date', '>=', $validated['date_from']),
            )
            ->when(
                filled($validated['date_to'] ?? null),
                fn ($q) => $q->whereDate('credit_note_date', '<=', $validated['date_to']),
            )
            ->when(filled($validated['search'] ?? null), function ($q) use ($validated): void {
                $term = '%'.$validated['search'].'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('credit_note_no', 'like', $term)
                        ->orWhere('bill_reference', 'like', $term)
                        ->orWhereHas('salesEmployee', function ($employeeQuery) use ($term): void {
                            $employeeQuery->where('full_name', 'like', $term)
                                ->orWhere('employee_code', 'like', $term);
                        })
                        ->orWhereHas('dealer', function ($dealerQuery) use ($term): void {
                            $dealerQuery->where('firm_name', 'like', $term)
                                ->orWhere('dealer_code', 'like', $term);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($notes->items())
                ->map(fn (CreditNote $note): array => $this->presenter->presentListItem($note))
                ->values(),
            'meta' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'total' => $notes->total(),
            ],
            'counts' => $this->teamCounts($request),
        ]);
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
        $dealer = $this->dealerAccess->resolveAccessibleActiveDealer(
            $request->user(),
            (int) $validated['dealer_id'],
        );

        if ($dealer === null && (int) $creditNote->dealer_id === (int) $validated['dealer_id']) {
            $dealer = $creditNote->dealer;
        }

        if ($dealer === null) {
            throw ValidationException::withMessages([
                'dealer_id' => 'Selected dealer is not available.',
            ]);
        }

        $creditNote = $this->updatePendingCreditNote->execute(
            creditNote: $creditNote,
            dealer: $dealer,
            payload: $validated,
            editor: $request->user(),
            editedByRole: CreditNote::EDITED_BY_ROLE_SALES_MANAGER,
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

    public function approve(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('approve', $creditNote);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $creditNote->approve($request->user()->id, $validated['remark'] ?? null);
        $fresh = $creditNote->fresh();

        return response()->json([
            'message' => 'Credit Note approved successfully.',
            'data' => $this->presenter->present($fresh),
        ]);
    }

    public function reject(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('reject', $creditNote);

        $validated = $request->validate([
            'remark' => ['required_without:rejection_reason', 'nullable', 'string', 'min:3', 'max:2000'],
            'rejection_reason' => ['required_without:remark', 'nullable', 'string', 'min:3', 'max:2000'],
        ]);

        $remark = $validated['remark'] ?? $validated['rejection_reason'];

        app(RejectCreditNoteWithRemarks::class)->execute(
            creditNote: $creditNote,
            actor: $request->user(),
            remark: (string) $remark,
            rejectedByRole: CreditNote::REJECTED_BY_ROLE_SALES_MANAGER,
        );

        return response()->json([
            'message' => 'Credit Note rejected successfully.',
            'data' => $this->presenter->present($creditNote->fresh()),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function teamCounts(Request $request): array
    {
        $base = $this->access->scopeToManagerTeam(CreditNote::query(), $request->user());

        return [
            'pending_approval' => (clone $base)->where('status', CreditNote::STATUS_PENDING_APPROVAL)->count(),
            'approved' => (clone $base)->where('status', CreditNote::STATUS_APPROVED)->count(),
            'completed' => (clone $base)->where('status', CreditNote::STATUS_COMPLETED)->count(),
            'rejected' => (clone $base)->where('status', CreditNote::STATUS_REJECTED)->count(),
            'all' => (clone $base)->count(),
        ];
    }
}
