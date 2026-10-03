<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerCreditLimitAudit;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerCreditExposureService;
use App\Services\Dealers\DealerCreditLimitWriter;
use App\Services\Dealers\DealerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDealerCreditLimitController extends Controller
{
    public function __construct(
        private readonly DealerCreditExposureService $exposure,
        private readonly DealerCreditLimitWriter $writer,
        private readonly DealerAccessService $access,
        private readonly DealerLedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Dealer::query()
            ->where('status', true)
            ->orderBy('firm_name');

        $this->access->scopeVisibleTo($query, $request->user());
        $this->ledger->scopeWithCurrentOutstanding($query);
        $this->exposure->scopeWithPendingExposure($query);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('firm_name', 'like', '%'.$search.'%')
                    ->orWhere('dealer_code', 'like', '%'.$search.'%')
                    ->orWhere('district', 'like', '%'.$search.'%')
                    ->orWhereHas('assignedEmployee', function ($employee) use ($search): void {
                        $employee->where('full_name', 'like', '%'.$search.'%');
                    });
            });
        }

        $dealers = $query
            ->with([
                'assignedEmployee:id,full_name,reporting_manager_id',
                'assignedEmployee.reportingManager:id,full_name',
                'creditLimit.updatedBy:id,name',
            ])
            ->get()
            ->map(fn (Dealer $dealer): array => $this->payload($dealer))
            ->values();

        return response()->json(['data' => $dealers]);
    }

    public function show(Request $request, Dealer $dealer): JsonResponse
    {
        $this->authorizeDealer($request, $dealer);

        $dealer->load([
            'assignedEmployee:id,full_name,reporting_manager_id',
            'assignedEmployee.reportingManager:id,full_name',
            'creditLimit.updatedBy:id,name',
        ]);

        return response()->json([
            'data' => $this->payload($dealer, true),
        ]);
    }

    public function history(Request $request, Dealer $dealer): JsonResponse
    {
        $this->authorizeDealer($request, $dealer);
        $this->writer->recordAutomaticExpiry($dealer);

        $audits = DealerCreditLimitAudit::query()
            ->where('dealer_id', $dealer->id)
            ->with('changedBy:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (DealerCreditLimitAudit $audit): array => [
                'id' => $audit->id,
                'action' => $audit->action,
                'previous_base' => $audit->previous_base !== null ? (float) $audit->previous_base : null,
                'new_base' => $audit->new_base !== null ? (float) $audit->new_base : null,
                'extension_amount' => $audit->extension_amount !== null ? (float) $audit->extension_amount : null,
                'effective_limit' => $audit->effective_limit !== null ? (float) $audit->effective_limit : null,
                'valid_until' => $audit->valid_until?->toDateString(),
                'remark' => $audit->remark,
                'changed_by' => $audit->changedBy?->name,
                'changed_by_role' => $audit->changed_by_role,
                'created_at' => $audit->created_at?->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            ])
            ->values();

        return response()->json(['data' => $audits]);
    }

    public function store(Request $request, Dealer $dealer): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $profile = $this->writer->setBase(
            $dealer,
            $request->user(),
            (float) $validated['amount'],
            (string) $validated['remark'],
        );

        $dealer->unsetRelation('creditLimit')->load('creditLimit.updatedBy:id,name');

        return response()->json([
            'message' => 'Credit limit saved.',
            'data' => $this->payload($dealer->fresh()),
            'profile_id' => $profile->id,
        ]);
    }

    public function extend(Request $request, Dealer $dealer): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'valid_until' => ['required', 'date'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $this->writer->extend(
            $dealer,
            $request->user(),
            (float) $validated['amount'],
            (string) $validated['valid_until'],
            (string) $validated['remark'],
        );

        return response()->json([
            'message' => 'Temporary extension saved.',
            'data' => $this->payload($dealer->fresh()),
        ]);
    }

    public function expire(Request $request, Dealer $dealer): JsonResponse
    {
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $this->writer->expireExtension($dealer, $request->user(), (string) $validated['remark']);

        return response()->json([
            'message' => 'Temporary extension removed.',
            'data' => $this->payload($dealer->fresh()),
        ]);
    }

    private function authorizeDealer(Request $request, Dealer $dealer): void
    {
        if (! $this->access->canAccessDealer($request->user(), $dealer)) {
            abort(403, 'You are not authorized to manage this dealer credit limit.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Dealer $dealer, bool $refreshFigures = false): array
    {
        if ($refreshFigures || $dealer->getAttribute('current_outstanding') === null) {
            $assessment = $this->exposure->assess($dealer, 0);
        } else {
            $assessment = $this->exposure->fromLoadedDealer($dealer, 0);
        }

        return array_merge($assessment->toArray(), [
            'dealer_code' => (string) $dealer->dealer_code,
            'dealer_name' => (string) $dealer->firm_name,
            'employee_name' => $dealer->assignedEmployee?->full_name,
            'manager_name' => $dealer->assignedEmployee?->reportingManager?->full_name,
            'district' => $dealer->district,
        ]);
    }
}
