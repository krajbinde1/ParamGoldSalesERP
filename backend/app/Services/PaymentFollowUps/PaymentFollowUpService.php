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

            $alreadyLogged = PaymentFollowUpEntry::query()
                ->where('cycle_id', $cycle->id)
                ->where('collection_id', $collection->id)
                ->where('entry_type', PaymentFollowUpEntry::TYPE_PAYMENT_RECEIVED)
                ->exists();

            if ($alreadyLogged) {
                return;
            }

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

            $receivedToDate = round((float) ($cycle->payment_received_amount ?? 0) + $receivedAmount, 2);
            $payload = [
                'payment_received_amount' => $receivedToDate,
            ];

            if ($outstandingAfter <= 0) {
                $payload['status'] = PaymentFollowUpCycle::STATUS_CLOSED;
                $payload['closed_at'] = $now;
                $payload['closing_outstanding'] = $outstandingAfter;
                $payload['collection_id'] = $collection->id;
            }

            $cycle->update($payload);
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
            'paymentFollowUpCycles.entries.collection:id,receipt_no,amount,collection_date,status',
            'paymentFollowUpCycles.collection:id,receipt_no,amount,collection_date,status',
        ]);

        $outstanding = $this->currentOutstanding($dealer);
        $lastPayment = $this->lastReceivedPayment($dealer);
        $open = $dealer->paymentFollowUpCycles->firstWhere('status', PaymentFollowUpCycle::STATUS_OPEN);
        $latestCycle = $dealer->paymentFollowUpCycles->sortByDesc('cycle_number')->first();
        $currentCycleId = (int) ($open?->id ?? $latestCycle?->id ?? 0);
        $latestClosed = $dealer->paymentFollowUpCycles
            ->where('status', PaymentFollowUpCycle::STATUS_CLOSED)
            ->sortByDesc('cycle_number')
            ->first();

        $status = PaymentFollowUpStatus::fromOpenNextDate(
            $open?->entries
                ?->filter(fn (PaymentFollowUpEntry $entry): bool => $entry->isFollowUp())
                ->last()?->next_follow_up_date?->toDateString(),
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
            'current_due' => $outstanding,
            'current_due_label' => IndianCurrency::format($outstanding),
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
                ->map(function (PaymentFollowUpCycle $cycle) use ($outstanding, $currentCycleId): array {
                    return $this->formatCycle($cycle, $outstanding, (int) $cycle->id === $currentCycleId);
                })
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
    public function formatCycle(PaymentFollowUpCycle $cycle, float $dealerCurrentOutstanding = 0.0, bool $isCurrent = false): array
    {
        $cycle->loadMissing([
            'entries.employee:id,full_name',
            'entries.createdBy:id,name',
            'entries.collection:id,receipt_no,amount,collection_date,status',
        ]);

        $entries = $cycle->entries->values();
        $followUpNumber = 0;
        $followUpCount = 0;
        $commitmentCount = 0;
        $missedCount = 0;
        $paymentTotal = 0.0;
        $formattedEntries = [];

        foreach ($entries as $entry) {
            $row = $this->formatEntry($entry);

            if ($entry->isFollowUp()) {
                $followUpNumber++;
                $followUpCount++;
                $row['follow_up_number'] = $followUpNumber;
                $row['timeline_kind'] = PaymentFollowUpEntry::TYPE_FOLLOW_UP;
                if ($entry->next_follow_up_date !== null || $entry->expected_amount !== null) {
                    $commitmentCount++;
                }
                $commitmentStatus = $this->commitmentOutcome($entry, $entries, $cycle->isClosed());
                $row['commitment_status'] = $commitmentStatus;
                $row['commitment_status_label'] = $commitmentStatus !== null
                    ? strtoupper($commitmentStatus)
                    : null;
                if ($commitmentStatus === PaymentFollowUpEntry::COMMITMENT_MISSED) {
                    $missedCount++;
                }
            } else {
                $paymentDate = $entry->collection?->collection_date?->toDateString()
                    ?? $entry->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString();
                $paymentAmount = $entry->expected_amount !== null
                    ? round((float) $entry->expected_amount, 2)
                    : round((float) ($entry->collection?->amount ?? 0), 2);
                $paymentTotal += $paymentAmount;
                $row['follow_up_number'] = null;
                $row['timeline_kind'] = PaymentFollowUpEntry::TYPE_PAYMENT_RECEIVED;
                $row['commitment_status'] = null;
                $row['commitment_status_label'] = null;
                $row['payment_amount'] = $paymentAmount;
                $row['payment_amount_label'] = IndianCurrency::format($paymentAmount);
                $row['payment_date'] = $paymentDate;
                $row['updated_current_due'] = round((float) $entry->outstanding_at_time, 2);
                $row['updated_current_due_label'] = IndianCurrency::format((float) $entry->outstanding_at_time);
            }

            $formattedEntries[] = $row;
        }

        $displayStatus = $this->cycleDisplayStatus($cycle, $entries);
        $currentDue = $cycle->isClosed()
            ? round((float) ($cycle->closing_outstanding ?? 0), 2)
            : round($dealerCurrentOutstanding, 2);
        $paymentReceived = $cycle->payment_received_amount !== null
            ? round((float) $cycle->payment_received_amount, 2)
            : round($paymentTotal, 2);

        return [
            'id' => $cycle->id,
            'cycle_number' => $cycle->cycle_number,
            'dealer_id' => $cycle->dealer_id,
            'employee_id' => $cycle->employee_id,
            'is_current' => $isCurrent,
            'opening_outstanding' => round((float) $cycle->opening_outstanding, 2),
            'opening_outstanding_label' => IndianCurrency::format((float) $cycle->opening_outstanding),
            'current_due' => $currentDue,
            'current_due_label' => IndianCurrency::format($currentDue),
            'started_at' => $cycle->started_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'started_date' => $cycle->started_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'status' => $cycle->status,
            'display_status' => $displayStatus,
            'status_label' => strtoupper($displayStatus),
            'closed_at' => $cycle->closed_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toIso8601String(),
            'closed_date' => $cycle->closed_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString(),
            'follow_up_count' => $followUpCount,
            'commitment_count' => $commitmentCount,
            'missed_commitment_count' => $missedCount,
            'payment_received_amount' => $paymentReceived,
            'payment_received_amount_label' => IndianCurrency::format($paymentReceived),
            'closing_outstanding' => $cycle->closing_outstanding !== null
                ? round((float) $cycle->closing_outstanding, 2)
                : null,
            'closing_outstanding_label' => $cycle->closing_outstanding !== null
                ? IndianCurrency::format((float) $cycle->closing_outstanding)
                : null,
            'collection_id' => $cycle->collection_id,
            'entries' => $formattedEntries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEntry(PaymentFollowUpEntry $entry): array
    {
        $entry->loadMissing(['employee:id,full_name', 'createdBy:id,name', 'collection:id,receipt_no,amount,collection_date,status']);

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
            'follow_up_at_label' => $entry->followed_up_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->format('d M Y, h:i A'),
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
            'employee_notification_status_label' => $this->reminderStatusLabel($entry->employee_notification_status),
            'employee_notification_sent_at' => $entry->employee_notification_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'whatsapp_status' => $entry->whatsapp_status,
            'whatsapp_status_label' => $this->reminderStatusLabel($entry->whatsapp_status),
            'whatsapp_sent_at' => $entry->whatsapp_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'whatsapp_error' => $entry->whatsapp_error,
            'commitment_whatsapp_status' => $entry->commitment_whatsapp_status,
            'commitment_whatsapp_status_label' => $this->reminderStatusLabel($entry->commitment_whatsapp_status),
            'commitment_whatsapp_sent_at' => $entry->commitment_whatsapp_sent_at
                ?->timezone(PaymentFollowUpStatus::TIMEZONE)
                ?->toIso8601String(),
            'commitment_whatsapp_error' => $entry->commitment_whatsapp_error,
            'collection_id' => $entry->collection_id,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaymentFollowUpEntry>  $entries
     */
    private function commitmentOutcome(PaymentFollowUpEntry $entry, $entries, bool $cycleClosed): ?string
    {
        if (! $entry->isFollowUp() || $entry->next_follow_up_date === null) {
            return null;
        }

        $commitmentDate = $entry->next_follow_up_date->toDateString();
        $kept = $entries->contains(function (PaymentFollowUpEntry $other) use ($entry, $commitmentDate): bool {
            if ($other->entry_type !== PaymentFollowUpEntry::TYPE_PAYMENT_RECEIVED || $other->id <= $entry->id) {
                return false;
            }

            $paymentDate = $other->collection?->collection_date?->toDateString()
                ?? $other->followed_up_at?->timezone(PaymentFollowUpStatus::TIMEZONE)?->toDateString();

            return $paymentDate !== null && $paymentDate <= $commitmentDate;
        });

        if ($kept) {
            return PaymentFollowUpEntry::COMMITMENT_KEPT;
        }

        $today = PaymentFollowUpStatus::todayDate();
        if ($commitmentDate < $today || $cycleClosed) {
            return PaymentFollowUpEntry::COMMITMENT_MISSED;
        }

        return PaymentFollowUpEntry::COMMITMENT_PENDING;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PaymentFollowUpEntry>  $entries
     */
    private function cycleDisplayStatus(PaymentFollowUpCycle $cycle, $entries): string
    {
        if ($cycle->isClosed()) {
            return 'closed';
        }

        $latestFollowUp = $entries
            ->filter(fn (PaymentFollowUpEntry $entry): bool => $entry->isFollowUp())
            ->last();
        $nextDate = $latestFollowUp?->next_follow_up_date?->toDateString();

        if ($nextDate !== null && $nextDate < PaymentFollowUpStatus::todayDate()) {
            return 'overdue';
        }

        return 'open';
    }

    private function reminderStatusLabel(?string $status): string
    {
        return match ($status) {
            PaymentFollowUpEntry::REMINDER_SENT => 'Sent',
            PaymentFollowUpEntry::REMINDER_FAILED => 'Failed',
            PaymentFollowUpEntry::REMINDER_SKIPPED => 'Skipped',
            PaymentFollowUpEntry::REMINDER_PENDING => 'Pending',
            default => filled($status) ? ucfirst($status) : '—',
        };
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
