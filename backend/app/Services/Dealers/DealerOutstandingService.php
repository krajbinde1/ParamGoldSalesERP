<?php

namespace App\Services\Dealers;

use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Outstanding listings for Admin / Director. Reuses DealerLedgerService SQL
 * and does not change ledger or outstanding calculation.
 */
final class DealerOutstandingService
{
    /** @var list<string> */
    private const SALES_TEAM_ROLES = [
        UserRole::Manager->value,
        UserRole::Employee->value,
    ];

    public function __construct(
        private readonly DealerLedgerService $ledger,
    ) {}

    public function total(?int $assignedEmployeeId = null): float
    {
        if ($assignedEmployeeId === null) {
            return $this->ledger->companyTotalOutstanding();
        }

        $sql = DealerLedgerService::currentOutstandingSql();

        $inner = Dealer::query()
            ->where('status', true)
            ->where('assigned_employee_id', $assignedEmployeeId)
            ->selectRaw($sql.' as current_outstanding')
            ->toBase();

        return $this->money(
            DB::query()
                ->fromSub($inner, 'outstanding_dealers')
                ->sum('current_outstanding')
        );
    }

    /**
     * @return Builder<Dealer>
     */
    public function dealersQuery(?int $assignedEmployeeId = null): Builder
    {
        $sql = DealerLedgerService::currentOutstandingSql();

        $query = Dealer::query()
            ->where('status', true)
            ->with(['assignedEmployee:id,full_name,employee_code'])
            ->when(
                $assignedEmployeeId !== null,
                fn (Builder $dealerQuery) => $dealerQuery->where('assigned_employee_id', $assignedEmployeeId),
            );

        $this->ledger->scopeWithCurrentOutstanding($query);

        return $query
            ->whereRaw($sql.' > 0')
            ->orderByRaw($sql.' DESC')
            ->orderBy('firm_name');
    }

    /**
     * All active dealers assigned to an employee, including zero outstanding.
     *
     * @return Builder<Dealer>
     */
    public function assignedDealersQuery(int $assignedEmployeeId): Builder
    {
        $sql = DealerLedgerService::currentOutstandingSql();

        $query = Dealer::query()
            ->where('status', true)
            ->where('assigned_employee_id', $assignedEmployeeId)
            ->with(['assignedEmployee:id,full_name,employee_code']);

        $this->ledger->scopeWithCurrentOutstanding($query);

        return $query
            ->orderByRaw($sql.' DESC')
            ->orderBy('firm_name');
    }

    /**
     * @return array{
     *     employee_name: string,
     *     employee_code: string|null,
     *     total: float,
     *     rows: list<array{employee_name: string, dealer_code: string, dealer_name: string, village: string, outstanding: float}>
     * }
     */
    public function employeeExportPayload(int $assignedEmployeeId): array
    {
        $employee = Employee::query()->findOrFail($assignedEmployeeId);
        $employeeName = $employee->full_name;

        $rows = $this->assignedDealersQuery($assignedEmployeeId)
            ->get()
            ->map(function (Dealer $dealer) use ($employeeName): array {
                $outstanding = $dealer->getAttribute('current_outstanding');

                return [
                    'employee_name' => $employeeName,
                    'dealer_code' => (string) $dealer->dealer_code,
                    'dealer_name' => (string) $dealer->firm_name,
                    'village' => filled($dealer->village) ? (string) $dealer->village : '-',
                    'outstanding' => $outstanding !== null
                        ? $this->money($outstanding)
                        : $this->ledger->getOutstanding($dealer),
                ];
            })
            ->values()
            ->all();

        return [
            'employee_name' => $employeeName,
            'employee_code' => $employee->employee_code,
            'scope_label' => $employeeName,
            'total' => $this->total($assignedEmployeeId),
            'rows' => $rows,
        ];
    }

    /**
     * PDF / Excel rows for All Employees or one assigned employee.
     *
     * @return array{
     *     employee_name: string,
     *     employee_code: string|null,
     *     scope_label: string,
     *     total: float,
     *     rows: list<array{employee_name: string, dealer_code: string, dealer_name: string, village: string, outstanding: float}>
     * }
     */
    public function exportPayload(?int $assignedEmployeeId = null): array
    {
        if ($assignedEmployeeId !== null) {
            return $this->employeeExportPayload($assignedEmployeeId);
        }

        $rows = $this->dealersQuery(null)
            ->get()
            ->map(function (Dealer $dealer): array {
                $employee = $dealer->assignedEmployee;
                $outstanding = $dealer->getAttribute('current_outstanding');

                return [
                    'employee_name' => $employee?->full_name ?? 'Unassigned',
                    'dealer_code' => (string) $dealer->dealer_code,
                    'dealer_name' => (string) $dealer->firm_name,
                    'village' => filled($dealer->village) ? (string) $dealer->village : '-',
                    'outstanding' => $outstanding !== null
                        ? $this->money($outstanding)
                        : $this->ledger->getOutstanding($dealer),
                ];
            })
            ->values()
            ->all();

        return [
            'employee_name' => 'All Employees',
            'employee_code' => null,
            'scope_label' => 'All Employees',
            'total' => $this->total(null),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null, dealer_count: int, total_outstanding: float}>
     */
    public function totalsByAssignedEmployee(): array
    {
        $sql = DealerLedgerService::currentOutstandingSql();

        $inner = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->select('assigned_employee_id')
            ->selectRaw($sql.' as current_outstanding')
            ->toBase();

        $rows = DB::query()
            ->fromSub($inner, 'outstanding_dealers')
            ->select('assigned_employee_id')
            ->selectRaw('COALESCE(SUM(current_outstanding), 0) as total_outstanding')
            ->selectRaw('SUM(CASE WHEN current_outstanding > 0 THEN 1 ELSE 0 END) as dealer_count')
            ->groupBy('assigned_employee_id')
            ->havingRaw('COALESCE(SUM(current_outstanding), 0) != 0')
            ->orderByDesc('total_outstanding')
            ->get();

        $employees = Employee::query()
            ->whereIn('id', $rows->pluck('assigned_employee_id'))
            ->get(['id', 'full_name', 'employee_code'])
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($employees): array {
                $employee = $employees->get((int) $row->assigned_employee_id);

                return [
                    'employee_id' => (int) $row->assigned_employee_id,
                    'employee_name' => $employee?->full_name ?? 'Unknown',
                    'employee_code' => $employee?->employee_code,
                    'dealer_count' => (int) $row->dealer_count,
                    'total_outstanding' => $this->money($row->total_outstanding),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function salesEmployeeOptions(): array
    {
        return $this->salesEmployees()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->assignmentLabel(),
            ])
            ->all();
    }

    /**
     * @return list<array{employee_id: int, employee_name: string, employee_code: string|null}>
     */
    public function salesEmployeeList(): array
    {
        return $this->salesEmployees()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Employee>
     */
    private function salesEmployees(): Collection
    {
        return Employee::query()
            ->where('status', true)
            ->whereHas(
                'user',
                fn (Builder $query) => $query->whereIn('role', self::SALES_TEAM_ROLES),
            )
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code']);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
