<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Services\Dealers\DealerLedgerService;
use App\Services\Dealers\DealerOutstandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director dealer outstanding list — reuses existing ledger outstanding SQL.
 */
class DirectorOutstandingDealerController extends Controller
{
    public function __construct(
        private readonly DealerLedgerService $ledger,
        private readonly DealerOutstandingService $outstanding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $outstandingSql = DealerLedgerService::currentOutstandingSql();

        $dealers = $this->outstanding->dealersQuery($employeeId)
            ->orderByRaw($outstandingSql.' DESC')
            ->orderBy('firm_name')
            ->get()
            ->map(fn (Dealer $dealer): array => $this->formatDealer($dealer))
            ->values();

        return response()->json([
            'total_outstanding' => $this->outstanding->total($employeeId),
            'data' => $dealers,
            'employees' => $this->outstanding->salesEmployeeList(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDealer(Dealer $dealer): array
    {
        $employee = $dealer->assignedEmployee;
        $outstanding = round(
            (float) ($dealer->getAttribute('current_outstanding') ?? $this->ledger->getOutstanding($dealer)),
            2
        );

        return [
            'id' => $dealer->id,
            'dealer_id' => $dealer->id,
            'dealer_name' => $dealer->firm_name,
            'dealer_code' => $dealer->dealer_code,
            'village' => $dealer->village,
            'employee_id' => $employee?->id ?? $dealer->assigned_employee_id,
            'employee_name' => $employee?->full_name,
            'employee_code' => $employee?->employee_code,
            'current_outstanding' => $outstanding,
        ];
    }
}
