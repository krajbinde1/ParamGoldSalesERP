<?php

namespace App\Http\Controllers\Api\Director;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Employee;
use App\Services\Dealers\DealerLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Director dealer outstanding list — reuses existing ledger outstanding SQL.
 */
class DirectorOutstandingDealerController extends Controller
{
    /** @var list<string> */
    private const SALES_TEAM_ROLES = [
        UserRole::Manager->value,
        UserRole::Employee->value,
    ];

    public function __construct(
        private readonly DealerLedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $outstandingSql = DealerLedgerService::currentOutstandingSql();

        $query = Dealer::query()
            ->where('status', true)
            ->with(['assignedEmployee:id,full_name,employee_code'])
            ->when(
                $employeeId !== null,
                fn ($dealerQuery) => $dealerQuery->where('assigned_employee_id', $employeeId),
            );

        $this->ledger->scopeWithCurrentOutstanding($query);

        $dealers = $query
            ->whereRaw($outstandingSql.' > 0')
            ->orderByRaw($outstandingSql.' DESC')
            ->orderBy('firm_name')
            ->get()
            ->map(fn (Dealer $dealer): array => $this->formatDealer($dealer))
            ->values();

        return response()->json([
            'total_outstanding' => $this->filteredTotal($employeeId),
            'data' => $dealers,
            'employees' => $this->salesEmployees(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDealer(Dealer $dealer): array
    {
        $employee = $dealer->assignedEmployee;
        $outstanding = $this->money(
            $dealer->getAttribute('current_outstanding') ?? $this->ledger->getOutstanding($dealer)
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

    private function filteredTotal(?int $employeeId): float
    {
        if ($employeeId === null) {
            return $this->ledger->companyTotalOutstanding();
        }

        $sql = DealerLedgerService::currentOutstandingSql();

        return $this->money(
            Dealer::query()
                ->where('status', true)
                ->where('assigned_employee_id', $employeeId)
                ->selectRaw('COALESCE(SUM('.$sql.'), 0) as total_outstanding')
                ->value('total_outstanding')
        );
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null}>
     */
    private function salesEmployees(): array
    {
        return Employee::query()
            ->where('status', true)
            ->whereHas(
                'user',
                fn ($query) => $query->whereIn('role', self::SALES_TEAM_ROLES),
            )
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code'])
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
            ])
            ->values()
            ->all();
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
