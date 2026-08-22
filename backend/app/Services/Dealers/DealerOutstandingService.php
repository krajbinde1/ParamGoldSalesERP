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
