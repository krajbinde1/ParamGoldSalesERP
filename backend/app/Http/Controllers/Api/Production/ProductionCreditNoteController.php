<?php

namespace App\Http\Controllers\Api\Production;

use App\Actions\CreditNotes\ApproveCreditNoteByProduction;
use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Support\CreditNotes\CreditNoteDetailPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionCreditNoteController extends Controller
{
    public function __construct(
        private readonly CreditNoteDetailPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CreditNote::class);

        $notes = CreditNote::query()
            ->where('type', CreditNote::TYPE_SALES_RETURN)
            ->where('move_to', CreditNote::MOVE_TO_FACTORY)
            ->where('status', CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL)
            ->with([
                'dealer:id,firm_name,dealer_code',
                'salesEmployee:id,full_name,employee_code',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (CreditNote $note): array => $this->presenter->presentListItem($note))
            ->values();

        return response()->json([
            'data' => $notes,
            'counts' => [
                'pending_production_approval' => $notes->count(),
            ],
        ]);
    }

    public function show(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('view', $creditNote);

        return response()->json([
            'data' => $this->presenter->present($creditNote),
        ]);
    }

    public function approve(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('approveAsProduction', $creditNote);

        $validated = $request->validate([
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $fresh = app(ApproveCreditNoteByProduction::class)->execute(
            $creditNote,
            $request->user(),
            $validated['remark'] ?? null,
        );

        return response()->json([
            'message' => 'Factory return approved and stock updated.',
            'data' => $this->presenter->present($fresh),
        ]);
    }

    public function reject(Request $request, CreditNote $creditNote): JsonResponse
    {
        $this->authorize('reject', $creditNote);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        app(RejectCreditNoteWithRemarks::class)->execute(
            creditNote: $creditNote,
            actor: $request->user(),
            remark: $validated['remark'],
            rejectedByRole: CreditNote::REJECTED_BY_ROLE_PRODUCTION_MANAGER,
        );

        return response()->json([
            'message' => 'Factory return rejected.',
            'data' => $this->presenter->present($creditNote->fresh()),
        ]);
    }
}
