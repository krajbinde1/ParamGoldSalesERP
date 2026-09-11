<?php

namespace App\Services\PaymentFollowUps;

use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\PaymentFollowUpCycle;
use App\Services\TallyLedger\TallyDealerLedgerService;
use Illuminate\Database\Eloquent\Builder;

final class PaymentFollowUpPerformanceService
{
    public function __construct(
        private readonly PaymentFollowUpService $followUps,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function employeeRows(?string $fromDate = null, ?string $toDate = null): array
    {
        $today = PaymentFollowUpStatus::todayDate();
        $outstandingSql = TallyDealerLedgerService::signedCurrentOutstandingSql('dealers');

        $employees = Employee::query()
            ->where('status', true)
            ->where('role', UserRole::Employee->value)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code']);

        $assignedCounts = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->selectRaw('assigned_employee_id, COUNT(*) as aggregate')
            ->groupBy('assigned_employee_id')
            ->pluck('aggregate', 'assigned_employee_id');

        $outstandingCounts = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->whereRaw($outstandingSql.' > 0')
            ->selectRaw('assigned_employee_id, COUNT(*) as aggregate')
            ->groupBy('assigned_employee_id')
            ->pluck('aggregate', 'assigned_employee_id');

        $openByDealer = PaymentFollowUpCycle::query()
            ->where('status', PaymentFollowUpCycle::STATUS_OPEN)
            ->with('latestFollowUpEntry')
            ->get()
            ->keyBy('dealer_id');

        $outstandingDealerIds = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->whereRaw($outstandingSql.' > 0')
            ->pluck('id')
            ->all();
        $outstandingDealerSet = array_fill_keys($outstandingDealerIds, true);

        $dealers = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->get(['id', 'assigned_employee_id']);

        $dueToday = [];
        $overdue = [];
        $noFollowUp = [];

        foreach ($dealers as $dealer) {
            $employeeId = (int) $dealer->assigned_employee_id;
            $open = $openByDealer->get($dealer->id);
            $next = $open?->latestFollowUpEntry?->next_follow_up_date?->toDateString();

            if ($open !== null && $next !== null) {
                if ($next === $today) {
                    $dueToday[$employeeId] = ($dueToday[$employeeId] ?? 0) + 1;
                } elseif ($next < $today) {
                    $overdue[$employeeId] = ($overdue[$employeeId] ?? 0) + 1;
                }
            } elseif (isset($outstandingDealerSet[$dealer->id])) {
                $noFollowUp[$employeeId] = ($noFollowUp[$employeeId] ?? 0) + 1;
            }
        }

        $closedQuery = PaymentFollowUpCycle::query()
            ->where('status', PaymentFollowUpCycle::STATUS_CLOSED);

        if (filled($fromDate)) {
            $closedQuery->whereDate('closed_at', '>=', $fromDate);
        }
        if (filled($toDate)) {
            $closedQuery->whereDate('closed_at', '<=', $toDate);
        }

        $completed = (clone $closedQuery)
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $payments = (clone $closedQuery)
            ->whereNotNull('payment_received_amount')
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->groupBy('employee_id')
            ->pluck('aggregate', 'employee_id');

        $rows = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $assigned = (int) ($assignedCounts[$id] ?? 0);
            if ($assigned === 0 && ($completed[$id] ?? 0) === 0) {
                continue;
            }

            $outstandingDealers = (int) ($outstandingCounts[$id] ?? 0);

            $rows[] = [
                'employee_id' => $id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'assigned_dealers' => $assigned,
                'dealers_with_outstanding' => $outstandingDealers,
                'due_today' => (int) ($dueToday[$id] ?? 0),
                'overdue' => (int) ($overdue[$id] ?? 0),
                'no_follow_up' => (int) ($noFollowUp[$id] ?? 0),
                'follow_ups_completed' => (int) ($completed[$id] ?? 0),
                'payments_received' => (int) ($payments[$id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>|null  $assignedEmployeeIds
     * @return array<string, int>
     */
    public function dashboardCounts(
        ?int $employeeId = null,
        ?int $dealerId = null,
        ?array $assignedEmployeeIds = null,
    ): array {
        if ($assignedEmployeeIds !== null && $assignedEmployeeIds === []) {
            return [
                PaymentFollowUpStatus::OVERDUE => 0,
                PaymentFollowUpStatus::DUE_TODAY => 0,
                PaymentFollowUpStatus::UPCOMING => 0,
                PaymentFollowUpStatus::NO_FOLLOW_UP => 0,
                PaymentFollowUpStatus::CLOSED => 0,
                'attention' => 0,
                'action_required' => 0,
                'requires_follow_up' => 0,
            ];
        }

        $query = $this->followUps->adminDealersQuery()
            ->when($employeeId, fn (Builder $builder) => $builder->where('assigned_employee_id', $employeeId))
            ->when($dealerId, fn (Builder $builder) => $builder->whereKey($dealerId))
            ->when(
                $assignedEmployeeIds !== null,
                fn (Builder $builder) => $builder->whereIn('assigned_employee_id', $assignedEmployeeIds),
            );

        $rows = $query->get()->map(fn (Dealer $dealer): array => $this->followUps->listRow($dealer))->all();
        $counts = $this->followUps->countByStatus($rows);
        $overdue = (int) ($counts[PaymentFollowUpStatus::OVERDUE] ?? 0);
        $dueToday = (int) ($counts[PaymentFollowUpStatus::DUE_TODAY] ?? 0);
        $noFollowUp = (int) ($counts[PaymentFollowUpStatus::NO_FOLLOW_UP] ?? 0);

        $actionRequiredIds = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (
                $status !== PaymentFollowUpStatus::OVERDUE
                && $status !== PaymentFollowUpStatus::DUE_TODAY
            ) {
                continue;
            }

            $rowDealerId = (int) ($row['dealer_id'] ?? 0);
            if ($rowDealerId > 0) {
                $actionRequiredIds[$rowDealerId] = true;
            }
        }
        $actionRequired = count($actionRequiredIds);

        return [
            ...$counts,
            'attention' => $actionRequired,
            'action_required' => $actionRequired,
            'requires_follow_up' => $overdue + $dueToday + $noFollowUp,
        ];
    }
}
