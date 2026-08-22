<?php

namespace App\Services\Dashboard;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Collection;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WeeklyTarget;
use App\Services\PaymentRequests\PaymentRequestApproverResolver;
use App\Services\Dealers\DealerLedgerService;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class DirectorDashboardDataService
{
    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?User $user = null): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $user ??= auth()->user();
        $today = AttendanceCalendar::today()->toDateString();
        $now = AttendanceCalendar::now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $activeEmployeeIds = Employee::query()
            ->where('status', true)
            ->whereHas('user', fn ($query) => $query->where('role', UserRole::Employee->value))
            ->pluck('id');

        $punchedInIds = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->whereNotNull('punch_in_time')
            ->when($activeEmployeeIds->isNotEmpty(), fn ($query) => $query->whereIn('employee_id', $activeEmployeeIds))
            ->distinct()
            ->pluck('employee_id');

        $activeRoutes = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->whereNotNull('punch_in_time')
            ->whereNull('punch_out_time')
            ->when($activeEmployeeIds->isNotEmpty(), fn ($query) => $query->whereIn('employee_id', $activeEmployeeIds))
            ->count();

        $orderStatusCounts = Order::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pendingApproval = (int) ($orderStatusCounts[Order::STATUS_PENDING_APPROVAL] ?? 0);
        $approved = (int) ($orderStatusCounts[Order::STATUS_APPROVED] ?? 0);
        $sentForBill = (int) ($orderStatusCounts[Order::STATUS_PENDING_FOR_BILLING] ?? 0);
        $billed = (int) ($orderStatusCounts[Order::STATUS_BILLED] ?? 0);
        $dispatched = (int) ($orderStatusCounts[Order::STATUS_DISPATCHED] ?? 0);
        $rejected = (int) ($orderStatusCounts[Order::STATUS_REJECTED] ?? 0);
        $onHold = (int) ($orderStatusCounts[Order::STATUS_ON_HOLD] ?? 0);
        $reverted = (int) ($orderStatusCounts[Order::STATUS_REVERTED_TO_MANAGER] ?? 0);
        $pendingOrders = 0;
        foreach (Order::activeNonDispatchedStatuses() as $status) {
            $pendingOrders += (int) ($orderStatusCounts[$status] ?? 0);
        }

        $delayPending24 = Order::query()
            ->where('status', Order::STATUS_PENDING_APPROVAL)
            ->where('created_at', '<=', $now->copy()->subHours(24))
            ->count();
        $delayBilling12 = Order::query()
            ->where('status', Order::STATUS_PENDING_FOR_BILLING)
            ->whereNotNull('sent_for_bill_at')
            ->where('sent_for_bill_at', '<=', $now->copy()->subHours(12))
            ->count();
        $delayDispatch24 = Order::query()
            ->where('status', Order::STATUS_BILLED)
            ->whereNotNull('billed_at')
            ->where('billed_at', '<=', $now->copy()->subHours(24))
            ->count();

        $todaySales = (float) Order::query()
            ->whereDate('order_date', $today)
            ->where('status', '!=', Order::STATUS_REJECTED)
            ->sum('grand_total');

        $todayCollection = (float) Collection::query()
            ->whereDate('collection_date', $today)
            ->sum('amount');

        $monthCollection = (float) Collection::query()
            ->whereBetween('collection_date', [$monthStart, $monthEnd])
            ->where('status', Collection::STATUS_RECEIVED)
            ->sum('amount');

        $ledger = app(DealerLedgerService::class);
        $totalOutstanding = $ledger->companyTotalOutstanding();
        $highOutstanding = $ledger->highOutstandingDealerCount();

        $dealerVisitsToday = DealerVisit::query()->whereDate('visit_date', $today)->count();
        $fieldVisitsToday = FieldActivity::query()->whereDate('activity_date', $today)->count();
        $employeesWithFieldToday = FieldActivity::query()
            ->whereDate('activity_date', $today)
            ->when($activeEmployeeIds->isNotEmpty(), fn ($query) => $query->whereIn('employee_id', $activeEmployeeIds))
            ->distinct()
            ->pluck('employee_id');
        $noFieldActivityToday = max($activeEmployeeIds->count() - $employeesWithFieldToday->count(), 0);

        $payments = $this->paymentSnapshot($user, $today);

        $this->memo = [
            'today' => $today,
            'today_sales' => $todaySales,
            'today_collection' => $todayCollection,
            'month_collection' => $monthCollection,
            'total_outstanding' => $totalOutstanding,
            'high_outstanding_dealers' => $highOutstanding,
            'active_employees' => $activeEmployeeIds->count(),
            'punched_in' => $punchedInIds->count(),
            'not_punched_in' => max($activeEmployeeIds->count() - $punchedInIds->count(), 0),
            'dealer_visits' => $dealerVisitsToday,
            'field_visits' => $fieldVisitsToday,
            'no_field_activity_today' => $noFieldActivityToday,
            'active_routes' => $activeRoutes,
            'pending_orders' => $pendingOrders,
            'pipeline' => [
                'placed' => $pendingApproval,
                'approved' => $approved,
                'sent_for_bill' => $sentForBill,
                'billed' => $billed,
                'dispatched' => $dispatched,
                'rejected' => $rejected,
                'on_hold' => $onHold,
                'reverted_to_manager' => $reverted,
            ],
            'delays' => [
                'pending_24h' => $delayPending24,
                'billing_12h' => $delayBilling12,
                'dispatch_24h' => $delayDispatch24,
            ],
            'payments' => $payments,
            'team_performance' => $this->teamPerformanceSnapshot($activeEmployeeIds, $monthStart, $monthEnd, $today),
            'recent_activity' => $this->recentActivity($today),
        ];

        return $this->memo;
    }

    public static function formatCompact(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);

        if ($abs >= 10000000) {
            return $sign.'₹'.number_format($abs / 10000000, 2).' Cr';
        }

        if ($abs >= 100000) {
            return $sign.'₹'.number_format($abs / 100000, 2).' L';
        }

        return $sign.'₹'.number_format($abs, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSnapshot(?User $user, string $today): array
    {
        $resolver = app(PaymentRequestApproverResolver::class);
        $counts = PaymentRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $pendingSecond = (int) ($counts[PaymentRequest::STATUS_PENDING_SECOND]->aggregate ?? 0);
        $pendingSecondAmount = (float) ($counts[PaymentRequest::STATUS_PENDING_SECOND]->total_amount ?? 0);

        $isFirst = $user !== null && $resolver->isFirstApprover($user);
        $isSecond = $user !== null && $resolver->isSecondApprover($user);

        $mine = $resolver->constrainPendingMyApproval(PaymentRequest::query(), $user)
            ->toBase()
            ->selectRaw('COUNT(*) as aggregate, COALESCE(SUM(amount), 0) as total_amount')
            ->first();
        $myCount = (int) ($mine->aggregate ?? 0);
        $myAmount = (float) ($mine->total_amount ?? 0);
        $myFilter = 'pending_my_approval';

        if ($isFirst) {
            $nextCount = $pendingSecond;
            $nextAmount = $pendingSecondAmount;
            $nextFilter = 'pending_bhagwan';
        } elseif ($isSecond) {
            $nextCount = (int) ($counts[PaymentRequest::STATUS_APPROVED_FOR_PAYMENT]->aggregate ?? 0);
            $nextAmount = (float) ($counts[PaymentRequest::STATUS_APPROVED_FOR_PAYMENT]->total_amount ?? 0);
            $nextFilter = 'approved_for_payment';
        } else {
            $nextCount = $pendingSecond;
            $nextAmount = $pendingSecondAmount;
            $nextFilter = 'pending_bhagwan';
        }

        $paidToday = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PAYMENT_DONE)
            ->whereDate('payment_done_at', $today)
            ->toBase()
            ->selectRaw('COUNT(*) as aggregate, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        return [
            'my_pending_count' => $myCount,
            'my_pending_amount' => $myAmount,
            'my_filter' => $myFilter,
            'next_count' => $nextCount,
            'next_amount' => $nextAmount,
            'next_filter' => $nextFilter,
            'paid_today_count' => (int) ($paidToday->aggregate ?? 0),
            'paid_today_amount' => (float) ($paidToday->total_amount ?? 0),
            'is_approver' => $isFirst || $isSecond,
        ];
    }

    /**
     * @param  SupportCollection<int, int|string>  $activeEmployeeIds
     * @return array{top: list<array<string, mixed>>, needs_attention: list<array<string, mixed>>}
     */
    private function teamPerformanceSnapshot(SupportCollection $activeEmployeeIds, string $monthStart, string $monthEnd, string $today): array
    {
        if ($activeEmployeeIds->isEmpty()) {
            return ['top' => [], 'needs_attention' => []];
        }

        $employees = Employee::query()
            ->whereIn('id', $activeEmployeeIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_code']);

        $targets = WeeklyTarget::query()
            ->where('status', 'active')
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereDate('week_start_date', '<=', $monthEnd)
            ->whereDate('week_end_date', '>=', $monthStart)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(sales_target) as sales_target')
            ->pluck('sales_target', 'employee_id');

        $sales = Order::query()
            ->whereIn('sales_employee_id', $activeEmployeeIds)
            ->where('status', Order::STATUS_DISPATCHED)
            ->where(function ($query) use ($monthStart, $monthEnd): void {
                $query->whereBetween('order_date', [$monthStart, $monthEnd])
                    ->orWhereRaw(
                        WeeklyTarget::updatedAtBusinessDateSql().' BETWEEN ? AND ?',
                        [$monthStart, $monthEnd],
                    );
            })
            ->groupBy('sales_employee_id')
            ->selectRaw('sales_employee_id, SUM(grand_total) as achieved')
            ->pluck('achieved', 'sales_employee_id');

        $visitsToday = DealerVisit::query()
            ->whereDate('visit_date', $today)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id');

        $fieldToday = FieldActivity::query()
            ->whereDate('activity_date', $today)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'employee_id');

        $rows = $employees->map(function (Employee $employee) use ($targets, $sales, $visitsToday, $fieldToday): array {
            $target = (float) ($targets[$employee->id] ?? 0);
            $achieved = (float) ($sales[$employee->id] ?? 0);
            $percentage = $target > 0 ? round(($achieved / $target) * 100, 1) : 0.0;
            $noVisit = (int) ($visitsToday[$employee->id] ?? 0) === 0;
            $noField = (int) ($fieldToday[$employee->id] ?? 0) === 0;

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'sales_percentage' => $percentage,
                'has_target' => $target > 0,
                'no_dealer_visit_today' => $noVisit,
                'no_field_visit_today' => $noField,
                'low_sales' => $target > 0 && $percentage < 50,
            ];
        });

        $top = $rows
            ->filter(fn (array $row): bool => $row['has_target'] && (float) $row['sales_percentage'] > 0)
            ->sortByDesc('sales_percentage')
            ->take(5)
            ->values()
            ->all();

        $topIds = collect($top)->pluck('employee_id')->all();

        $needsAttention = $rows
            ->filter(function (array $row) use ($topIds): bool {
                if (in_array($row['employee_id'], $topIds, true)) {
                    return false;
                }

                if ($row['low_sales']) {
                    return true;
                }

                return $row['has_target']
                    && $row['no_dealer_visit_today']
                    && $row['no_field_visit_today']
                    && (float) $row['sales_percentage'] < 75;
            })
            ->sortBy('sales_percentage')
            ->take(5)
            ->values()
            ->all();

        return [
            'top' => $top,
            'needs_attention' => $needsAttention,
        ];
    }

    /**
     * @return list<array{text: string, meta: string, at: Carbon}>
     */
    private function recentActivity(string $today): array
    {
        $items = collect();

        Order::query()
            ->with(['salesEmployee:id,full_name', 'dealer:id,firm_name'])
            ->where(function ($query): void {
                $query->whereNotNull('approved_at')
                    ->orWhereNotNull('billed_at')
                    ->orWhereNotNull('dispatched_at');
            })
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->each(function (Order $order) use ($items): void {
                if ($order->dispatched_at) {
                    $items->push([
                        'text' => 'Order '.$order->shortOrderNo().' dispatched',
                        'meta' => $order->dealer?->firm_name,
                        'at' => $order->dispatched_at,
                    ]);
                } elseif ($order->billed_at) {
                    $items->push([
                        'text' => 'Order '.$order->shortOrderNo().' billed',
                        'meta' => $order->dealer?->firm_name,
                        'at' => $order->billed_at,
                    ]);
                } elseif ($order->approved_at) {
                    $items->push([
                        'text' => 'Order '.$order->shortOrderNo().' approved by Sales Manager',
                        'meta' => $order->salesEmployee?->full_name,
                        'at' => $order->approved_at,
                    ]);
                }
            });

        PaymentRequest::query()
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->each(function (PaymentRequest $request) use ($items): void {
                $amount = self::formatCompact((float) $request->amount);
                if ($request->status === PaymentRequest::STATUS_PAYMENT_DONE && $request->payment_done_at) {
                    $items->push([
                        'text' => 'Payment Request '.$amount.' paid',
                        'meta' => $request->vendor_name,
                        'at' => $request->payment_done_at,
                    ]);
                } elseif (in_array($request->status, [
                    PaymentRequest::STATUS_PENDING_FIRST,
                    PaymentRequest::STATUS_PENDING_SECOND,
                ], true)) {
                    $items->push([
                        'text' => 'Payment Request '.$amount.' awaiting approval',
                        'meta' => $request->vendor_name,
                        'at' => $request->updated_at,
                    ]);
                }
            });

        Collection::query()
            ->with('dealer:id,firm_name')
            ->where('status', Collection::STATUS_RECEIVED)
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->each(function (Collection $collection) use ($items): void {
                $items->push([
                    'text' => 'Collection '.self::formatCompact((float) $collection->amount).' received',
                    'meta' => $collection->dealer?->firm_name,
                    'at' => $collection->collection_date?->startOfDay() ?? $collection->created_at,
                ]);
            });

        Attendance::query()
            ->with('employee:id,full_name')
            ->whereDate('attendance_date', $today)
            ->whereNotNull('punch_in_time')
            ->orderByDesc('punch_in_time')
            ->limit(5)
            ->get()
            ->each(function (Attendance $attendance) use ($items): void {
                $at = $attendance->punchInAt();
                if ($at === null) {
                    return;
                }
                $items->push([
                    'text' => ($attendance->employee?->full_name ?? 'Employee').' punched in',
                    'meta' => null,
                    'at' => $at,
                ]);
            });

        return $items
            ->sortByDesc(fn (array $row): int => $row['at']?->timestamp ?? 0)
            ->take(10)
            ->map(function (array $row): array {
                /** @var Carbon|null $at */
                $at = $row['at'];

                return [
                    'text' => $row['text'],
                    'meta' => $row['meta'],
                    'date' => $at?->timezone(AttendanceCalendar::TIMEZONE)->format('d M Y'),
                    'time' => $at?->timezone(AttendanceCalendar::TIMEZONE)->format('h:i A'),
                ];
            })
            ->values()
            ->all();
    }
}
