<?php

namespace App\Services\PaymentFollowUps;

use App\Models\Collection;
use App\Models\Dealer;
use App\Models\PaymentFollowUpCycle;
use App\Models\PaymentFollowUpEntry;
use App\Models\User;
use App\Services\Dealers\DealerAccessService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Support\IndianCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentFollowUpService
{
    public function __construct(
        private readonly TallyDealerLedgerService $ledger,
        private readonly DealerAccessService $dealerAccess,
        private readonly PaymentFollowUpCommitmentService $commitments,
    ) {}

    public function currentOutstanding(Dealer $dealer): float
    {
        return $this->ledger->signedCurrentOutstanding($dealer);
    }

    /**
     * @return array<string, mixed>
     */
    public function listForEmployee(User $user, ?string $search = null): array
    {
        $employee = $user->employee;
        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee' => 'Employee profile is required.',
            ]);
        }

        return $this->listForAssignedEmployee((int) $employee->id, $search);
    }

    /**
     * Director / assigned-employee dealer list. Does not change follow-up workflow.
     *
     * @return array<string, mixed>
     */
    public function listForAssignedEmployee(int $employeeId, ?string $search = null): array
    {
        $dealers = $this->assignedDealersQuery($employeeId, $search)->get();
        $rows = $dealers->map(fn (Dealer $dealer): array => $this->listRow($dealer))->all();

        usort($rows, function (array $left, array $right): int {
            $priority = PaymentFollowUpStatus::priority($left['status']) <=> PaymentFollowUpStatus::priority($right['status']);
            if ($priority !== 0) {
                return $priority;
            }

            $next = strcmp((string) ($left['next_follow_up_date'] ?? '9999-12-31'), (string) ($right['next_follow_up_date'] ?? '9999-12-31'));
            if ($next !== 0) {
                return $next;
            }

            return strcasecmp((string) $left['dealer_name'], (string) $right['dealer_name']);
        });

        return [
            'counts' => $this->countByStatus($rows),
            'data' => array_values($rows),
        ];
    }

    /**
     * Director read-only history. Never allows adding follow-ups.
     *
     * @return array<string, mixed>
     */
    public function showForDirector(int $dealerId): array
    {
        $dealer = Dealer::query()->findOrFail($dealerId);
        $detail = $this->dealerDetail($dealer);
        $detail['can_add_follow_up'] = false;

        return $detail;
    }

    /**
     * @return array<string, mixed>
     */
    public function showForEmployee(User $user, int $dealerId): array
    {
        $dealer = $this->resolveAssignedDealer($user, $dealerId);

        return $this->dealerDetail($dealer);
    }

    /**
     * @return array<string, mixed>
     */
    public function addFollowUp(
        User $user,
        int $dealerId,
        string $remark,
        ?float $expectedAmount,
        string $nextFollowUpDate,
    ): array {
        $dealer = $this->resolveAssignedDealer($user, $dealerId);
        $employee = $user->employee;
        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee' => 'Employee profile is required.',
            ]);
        }

        $remark = trim($remark);
        if ($remark === '') {
            throw ValidationException::withMessages([
                'remark' => 'Follow-up remark is required.',
            ]);
        }

        $nextDate = Carbon::parse($nextFollowUpDate, PaymentFollowUpStatus::TIMEZONE)->startOfDay();
        $today = PaymentFollowUpStatus::today();
        if ($nextDate->lt($today)) {
            throw ValidationException::withMessages([
                'next_follow_up_date' => 'Next follow-up date cannot be in the past.',
            ]);
        }

        $expected = $expectedAmount !== null ? round($expectedAmount, 2) : null;
        if ($expected !== null && $expected <= 0) {
            throw ValidationException::withMessages([
                'expected_amount' => 'Expected payment amount must be greater than zero.',
            ]);
        }

        $entryId = null;

        DB::transaction(function () use ($dealer, $employee, $user, $remark, $expected, $nextDate, &$entryId): void {
            $dealer = Dealer::query()->whereKey($dealer->id)->lockForUpdate()->firstOrFail();
            $outstanding = $this->currentOutstanding($dealer);

            $open = PaymentFollowUpCycle::query()
                ->where('dealer_id', $dealer->id)
                ->where('status', PaymentFollowUpCycle::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                if ($outstanding <= 0) {
                    throw ValidationException::withMessages([
                        'dealer_id' => 'This dealer has no outstanding to follow up.',
                    ]);
                }

                $nextNumber = (int) PaymentFollowUpCycle::query()
                    ->where('dealer_id', $dealer->id)
                    ->max('cycle_number') + 1;

                $open = PaymentFollowUpCycle::query()->create([
                    'dealer_id' => $dealer->id,
                    'employee_id' => $employee->id,
                    'cycle_number' => $nextNumber,
                    'opening_outstanding' => $outstanding,
                    'started_at' => Carbon::now(PaymentFollowUpStatus::TIMEZONE),
                    'status' => PaymentFollowUpCycle::STATUS_OPEN,
                    'created_by' => $user->id,
                ]);
            }

            $entry = PaymentFollowUpEntry::query()->create([
                'cycle_id' => $open->id,
                'dealer_id' => $dealer->id,
                'employee_id' => $employee->id,
                'created_by' => $user->id,
                'entry_type' => PaymentFollowUpEntry::TYPE_FOLLOW_UP,
                'followed_up_at' => Carbon::now(PaymentFollowUpStatus::TIMEZONE),
                'remark' => $remark,
                'outstanding_at_time' => $outstanding,
                'expected_amount' => $expected,
                'next_follow_up_date' => $nextDate->toDateString(),
                'employee_notification_status' => PaymentFollowUpEntry::REMINDER_PENDING,
                'whatsapp_status' => PaymentFollowUpEntry::REMINDER_PENDING,
                'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_PENDING,
            ]);

            $entryId = (int) $entry->id;
        });

        if ($entryId !== null) {
            $this->commitments->sendForEntry(
                PaymentFollowUpEntry::query()->findOrFail($entryId),
            );
        }

        return $this->dealerDetail($dealer->fresh() ?? $dealer);
    }

    public function closeOpenCycleFromReceivedCollection(Collection $collection): void
    {
        if ($collection->status !== Collection::STATUS_RECEIVED || $collection->dealer_id === null) {
            return;
        }

        DB::transaction(function () use ($collection): void {
            $dealer = Dealer::query()->whereKey($collection->dealer_id)->lockForUpdate()->first();
            if ($dealer === null) {
                return;
            }

            $cycle = PaymentFollowUpCycle::query()
                ->where('dealer_id', $dealer->id)
                ->where('status', PaymentFollowUpCycle::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($cycle === null) {
                return;
            }

            $outstandingAfter = $this->currentOutstanding($dealer);
            $receivedAmount = round((float) $collection->amount, 2);
            $now = Carbon::now(PaymentFollowUpStatus::TIMEZONE);
            $receipt = filled($collection->receipt_no)
                ? (string) $collection->receipt_no
                : 'Collection #'.$collection->id;

            PaymentFollowUpEntry::query()->create([
                'cycle_id' => $cycle->id,
                'dealer_id' => $dealer->id,
                'employee_id' => $cycle->employee_id,
                'created_by' => null,
                'entry_type' => PaymentFollowUpEntry::TYPE_PAYMENT_RECEIVED,
                'followed_up_at' => $now,
                'remark' => 'Payment received via Collection '.$receipt.'.',
                'outstanding_at_time' => $outstandingAfter,
                'expected_amount' => $receivedAmount,
                'next_follow_up_date' => null,
                'employee_notification_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                'whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                'commitment_whatsapp_status' => PaymentFollowUpEntry::REMINDER_SKIPPED,
                'collection_id' => $collection->id,
            ]);

            $cycle->update([
                'status' => PaymentFollowUpCycle::STATUS_CLOSED,
                'closed_at' => $now,
                'payment_received_amount' => $receivedAmount,
                'closing_outstanding' => $outstandingAfter,
                'collection_id' => $collection->id,
            ]);
        });
    }

    /**
     * @return Builder<Dealer>
     */
    public function assignedDealersQuery(int $employeeId, ?string $search = null): Builder
    {
        $query = Dealer::query()
            ->where('status', true)
            ->where('assigned_employee_id', $employeeId)
            ->with([
                'assignedEmployee:id,full_name',
                'openPaymentFollowUpCycle.latestFollowUpEntry',
                'latestPaymentFollowUpCycle',
                'latestPaymentFollowUpEntry.employee:id,full_name',
            ]);

        if (filled($search)) {
            $term = '%'.trim((string) $search).'%';
            $query->where('firm_name', 'like', $term);
        }

        $this->ledger->scopeWithCurrentOutstanding($query);

        return $query->orderBy('firm_name');
    }

    /**
     * @param  Builder<Dealer>  $query
     * @return Builder<Dealer>
     */
    public function applyStatusFilter(Builder $query, ?string $status): Builder
    {
        if (! filled($status)) {
            return $query;
        }

        $today = PaymentFollowUpStatus::todayDate();
        $outstandingSql = TallyDealerLedgerService::signedCurrentOutstandingSql($query->getModel()->getTable());

        return match ($status) {
            PaymentFollowUpStatus::OVERDUE => $query->whereHas(
                'openPaymentFollowUpCycle.latestFollowUpEntry',
                fn (Builder $entry) => $entry->whereDate('next_follow_up_date', '<', $today),
            ),
            PaymentFollowUpStatus::DUE_TODAY => $query->whereHas(
                'openPaymentFollowUpCycle.latestFollowUpEntry',
                fn (Builder $entry) => $entry->whereDate('next_follow_up_date', '=', $today),
            ),
            PaymentFollowUpStatus::UPCOMING => $query->whereHas(
                'openPaymentFollowUpCycle.latestFollowUpEntry',
                fn (Builder $entry) => $entry->whereDate('next_follow_up_date', '>', $today),
            ),
            PaymentFollowUpStatus::CLOSED => $query
                ->whereDoesntHave('openPaymentFollowUpCycle')
                ->whereHas(
                    'latestPaymentFollowUpCycle',
                    fn (Builder $cycle) => $cycle->where('status', PaymentFollowUpCycle::STATUS_CLOSED),
                )
                ->whereRaw($outstandingSql.' <= 0'),
            PaymentFollowUpStatus::NO_FOLLOW_UP => $query
                ->whereDoesntHave('openPaymentFollowUpCycle')
                ->where(function (Builder $inner) use ($outstandingSql): void {
                    $inner->whereDoesntHave('latestPaymentFollowUpCycle')
                        ->orWhereRaw($outstandingSql.' > 0');
                }),
            default => $query,
        };
    }

    /**
     * Admin monitor: all assigned active dealers.
     *
     * @return Builder<Dealer>
     */
    public function adminDealersQuery(): Builder
    {
        $query = Dealer::query()
            ->where('status', true)
            ->whereNotNull('assigned_employee_id')
            ->with([
                'assignedEmployee:id,full_name,employee_code',
                'openPaymentFollowUpCycle.latestFollowUpEntry.employee:id,full_name',
                'latestPaymentFollowUpCycle',
                'latestPaymentFollowUpEntry.employee:id,full_name',
            ]);

        $this->ledger->scopeWithCurrentOutstanding($query);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function dealerDetail(Dealer $dealer): array
    {
        $dealer->load([
            'assignedEmployee:id,full_name',
            'paymentFollowUpCycles' => fn ($query) => $query->orderBy('cycle_number'),
            'paymentFollowUpCycles.entries.employee:id,full_name',
            'paymentFollowUpCycles.entries.createdBy:id,name',
            'paymentFollowUpCycles.collection:id,receipt_no,amount,collection_date,status',
        ]);

        $outstanding = $this->currentOutstanding($dealer);
        $lastPayment = $this->lastReceivedPayment($dealer);
        $open = $dealer->paymentFollowUpCycles->firstWhere('status', PaymentFollowUpCycle::STATUS_OPEN);
        $latestClosed = $dealer->paymentFollowUpCycles
            ->where('status', PaymentFollowUpCycle::STATUS_CLOSED)
            ->sortByDesc('cycle_number')
            ->first();

        $status = PaymentFollowUpStatus::fromOpenNextDate(
            $open?->latestFollowUpEntry?->next_follow_up_date?->toDateString(),
            $open !== null,
            $latestClosed !== null,
            $outstanding,
        );

        return [
            'dealer' => [
                'id' => $dealer->id,
                'dealer_code' => $dealer->dealer_code,
                'firm_name' => $dealer->firm_name,
                'village' => $dealer->village,
                'mobile' => $dealer->mobile,
                'assigned_employee_id' => $dealer->assigned_employee_id,
                'assigned_employee_name' => $dealer->assignedEmployee?->full_name,
            ],
            'current_outstanding' => $outstanding,
            'current_outstanding_label' => IndianCurrency::format($outstanding),
            'last_payment_date' => $lastPayment['date'] ?? null,
            'last_payment_amount' => $lastPayment['amount'] ?? null,
            'last_payment_amount_label' => isset($lastPayment['amount'])
                ? IndianCurrency::format((float) $lastPayment['amount'])
                : null,
            'status' => $status,
            'status_label' => PaymentFollowUpStatus::label($status),
            'can_add_follow_up' => $open !== null || $outstanding > 0,
            'open_cycle_id' => $open?->id,
            'cycles' => $dealer->paymentFollowUpCycles
                ->map(fn (PaymentFollowUpCycle $cycle): array => $this->formatCycle($cycle))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(Dealer $dealer): array
    {
        $outstanding = $dealer->getAttribute('current_outstanding');
        $outstanding = $outstanding !== null
            ? round((float) $outstanding, 2)
            : $this->currentOutstanding($dealer);

        $open = $dealer->openPaymentFollowUpCycle;
        $latestCycle = $dealer->latestPaymentFollowUpCycle;
        $latestEntry = $dealer->latestPaymentFollowUpEntry;
        $openEntry = $open?->latestFollowUpEntry;

        $status = PaymentFollowUpStatus::fromOpenNextDate(
            $openEntry?->next_follow_up_date?->toDateString(),
            $open !== null,
            $latestCycle !== null && $latestCycle->isClosed(),
            $outstanding,
        );

        return [
            'dealer_id' => $dealer->id,
            'dealer_name' => $dealer->firm_name,
            'village' => $dealer->village,
            'assigned_employee_id' => $dealer->assigned_employee_id,
            'assigned_employee_name' => $dealer->assignedEmployee?->full_name,
            'current_outstanding' => $outstanding,
            'current_outstanding_label' => IndianCurrency::format($outstanding),
            'last_follow_up_at' => $latestEntry?->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'last_follow_up_date' => $latestEntry?->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'last_remark' => $latestEntry?->remark,
            'expected_amount' => $openEntry?->expected_amount !== null
                ? round((float) $openEntry->expected_amount, 2)
                : null,
            'expected_amount_label' => $openEntry?->expected_amount !== null
                ? IndianCurrency::format((float) $openEntry->expected_amount)
                : null,
            'next_follow_up_date' => $openEntry?->next_follow_up_date?->toDateString(),
            'status' => $status,
            'status_label' => PaymentFollowUpStatus::label($status),
            'open_cycle_id' => $open?->id,
            'open_cycle_number' => $open?->cycle_number,
            'reminder_whatsapp_status' => $openEntry?->whatsapp_status,
            'reminder_employee_status' => $openEntry?->employee_notification_status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatCycle(PaymentFollowUpCycle $cycle): array
    {
        $cycle->loadMissing(['entries.employee:id,full_name', 'entries.createdBy:id,name']);

        return [
            'id' => $cycle->id,
            'cycle_number' => $cycle->cycle_number,
            'dealer_id' => $cycle->dealer_id,
            'employee_id' => $cycle->employee_id,
            'opening_outstanding' => round((float) $cycle->opening_outstanding, 2),
            'opening_outstanding_label' => IndianCurrency::format((float) $cycle->opening_outstanding),
            'started_at' => $cycle->started_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'started_date' => $cycle->started_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'status' => $cycle->status,
            'status_label' => $cycle->isClosed() ? 'CLOSED' : 'OPEN',
            'closed_at' => $cycle->closed_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'closed_date' => $cycle->closed_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'payment_received_amount' => $cycle->payment_received_amount !== null
                ? round((float) $cycle->payment_received_amount, 2)
                : null,
            'payment_received_amount_label' => $cycle->payment_received_amount !== null
                ? IndianCurrency::format((float) $cycle->payment_received_amount)
                : null,
            'closing_outstanding' => $cycle->closing_outstanding !== null
                ? round((float) $cycle->closing_outstanding, 2)
                : null,
            'closing_outstanding_label' => $cycle->closing_outstanding !== null
                ? IndianCurrency::format((float) $cycle->closing_outstanding)
                : null,
            'collection_id' => $cycle->collection_id,
            'entries' => $cycle->entries
                ->map(fn (PaymentFollowUpEntry $entry): array => $this->formatEntry($entry))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEntry(PaymentFollowUpEntry $entry): array
    {
        $entry->loadMissing(['employee:id,full_name', 'createdBy:id,name']);

        return [
            'id' => $entry->id,
            'cycle_id' => $entry->cycle_id,
            'dealer_id' => $entry->dealer_id,
            'employee_id' => $entry->employee_id,
            'employee_name' => $entry->employee?->full_name,
            'created_by' => $entry->created_by,
            'created_by_name' => $entry->createdBy?->name ?? $entry->employee?->full_name,
            'entry_type' => $entry->entry_type,
            'followed_up_at' => $entry->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'follow_up_date' => $entry->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'remark' => $entry->remark,
            'outstanding_at_time' => round((float) $entry->outstanding_at_time, 2),
            'outstanding_at_time_label' => IndianCurrency::format((float) $entry->outstanding_at_time),
            'expected_amount' => $entry->expected_amount !== null
                ? round((float) $entry->expected_amount, 2)
                : null,
            'expected_amount_label' => $entry->expected_amount !== null
                ? IndianCurrency::format((float) $entry->expected_amount)
                : null,
            'next_follow_up_date' => $entry->next_follow_up_date?->toDateString(),
            'employee_notification_status' => $entry->employee_notification_status,
            'employee_notification_sent_at' => $entry->employee_notification_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'whatsapp_status' => $entry->whatsapp_status,
            'whatsapp_sent_at' => $entry->whatsapp_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'whatsapp_error' => $entry->whatsapp_error,
            'commitment_whatsapp_status' => $entry->commitment_whatsapp_status,
            'commitment_whatsapp_sent_at' => $entry->commitment_whatsapp_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'commitment_whatsapp_error' => $entry->commitment_whatsapp_error,
            'collection_id' => $entry->collection_id,
        ];
    }

    public function resolveAssignedDealer(User $user, int $dealerId): Dealer
    {
        $dealer = $this->dealerAccess->resolveAccessibleActiveDealer($user, $dealerId);

        if ($dealer === null || $user->employee_id === null
            || (int) $dealer->assigned_employee_id !== (int) $user->employee_id) {
            throw ValidationException::withMessages([
                'dealer_id' => 'This dealer is not assigned to you.',
            ]);
        }

        return $dealer;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function countByStatus(array $rows): array
    {
        $counts = [
            PaymentFollowUpStatus::OVERDUE => 0,
            PaymentFollowUpStatus::DUE_TODAY => 0,
            PaymentFollowUpStatus::UPCOMING => 0,
            PaymentFollowUpStatus::NO_FOLLOW_UP => 0,
            PaymentFollowUpStatus::CLOSED => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * @return array{date: ?string, amount: ?float}|array{}
     */
    public function lastReceivedPayment(Dealer $dealer): array
    {
        $collection = Collection::query()
            ->where('dealer_id', $dealer->id)
            ->where('status', Collection::STATUS_RECEIVED)
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->first();

        if ($collection === null) {
            return [];
        }

        return [
            'date' => $collection->collection_date?->toDateString(),
            'amount' => round((float) $collection->amount, 2),
        ];
    }
}
