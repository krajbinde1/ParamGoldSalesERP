<?php

namespace App\Services\Orders;

use App\Enums\CompanyTransportEntryKind;
use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportLedgerSource;
use App\Enums\CompanyTransportPaymentMode;
use App\Enums\TransportChargeType;
use App\Enums\UserRole;
use App\Models\CompanyTransportLedgerEntry;
use App\Models\CompanyTransportLedgerEntryAudit;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\IndianCurrency;
use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyTransportLedgerService
{
    public const ATTACHMENT_DIRECTORY = 'company-transport-expenses';

    /**
     * Post or adjust the single linked transport CREDIT for a dispatched order.
     * Company Transport and Transport Charges Extra share this ledger.
     * Idempotent: identical live credit is not duplicated.
     */
    public function syncDispatchedOrderCredit(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $current = $this->currentCreditForOrder((int) $locked->id);
            $type = $this->resolveChargeType($locked);
            $desiredAmount = $this->desiredTransportCredit($locked);

            if ($desiredAmount <= 0.004) {
                if ($current !== null) {
                    $this->reverseCredit(
                        $current,
                        $locked,
                        $actor,
                        'Transport credit reversed',
                    );
                }

                return;
            }

            $vehicleNumber = filled($locked->vehicle_number)
                ? Vehicle::normalizeVehicleNumber((string) $locked->vehicle_number)
                : null;
            $vehicleId = filled($locked->vehicle_id) ? (int) $locked->vehicle_id : null;
            $typeValue = $type?->value;

            if (
                $current !== null
                && abs((float) $current->credit_amount - $desiredAmount) < 0.005
                && (string) ($current->vehicle_number ?? '') === (string) ($vehicleNumber ?? '')
                && (int) ($current->vehicle_id ?? 0) === (int) ($vehicleId ?? 0)
                && (string) ($current->transport_charge_type ?? '') === (string) ($typeValue ?? '')
            ) {
                return;
            }

            if ($current !== null) {
                $this->reverseCredit(
                    $current,
                    $locked,
                    $actor,
                    'Transport credit adjusted after dispatched order correction',
                );
            }

            $this->postCredit($locked, $actor, $desiredAmount, $type, $vehicleId, $vehicleNumber);
        });
    }

    /**
     * Post missing credits for dispatched orders with either transport type.
     * Safe to run multiple times: skips orders that already have an unreversed credit.
     *
     * @return array{posted: int, skipped: int}
     */
    public function backfillDispatchedOrderCredits(): array
    {
        $posted = 0;
        $skipped = 0;

        Order::query()
            ->where('status', Order::STATUS_DISPATCHED)
            ->where('transport_amount', '>', 0)
            ->orderBy('id')
            ->each(function (Order $order) use (&$posted, &$skipped): void {
                $type = $this->resolveChargeType($order);
                if ($type === null || $this->desiredTransportCredit($order) <= 0.004) {
                    $skipped++;

                    return;
                }

                $existing = CompanyTransportLedgerEntry::query()
                    ->where('order_id', $order->id)
                    ->where('entry_kind', CompanyTransportEntryKind::Credit)
                    ->whereNull('reversed_at')
                    ->first();

                if ($existing !== null) {
                    if (blank($existing->transport_charge_type)) {
                        $existing->update([
                            'transport_charge_type' => $type->value,
                            'particulars' => $type->label().' — '.$order->order_no,
                        ]);
                    }
                    $skipped++;

                    return;
                }

                $actor = $this->backfillActor($order);
                if ($actor === null) {
                    $skipped++;

                    return;
                }

                $this->syncDispatchedOrderCredit($order, $actor);
                $posted++;
            });

        return [
            'posted' => $posted,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordExpense(User $actor, array $payload): CompanyTransportLedgerEntry
    {
        return DB::transaction(function () use ($actor, $payload): CompanyTransportLedgerEntry {
            $normalized = $this->normalizeExpensePayload($payload, $actor);
            $entry = CompanyTransportLedgerEntry::query()->create($normalized);
            $this->audit($entry, $actor, 'created', null, $this->snapshot($entry));

            return $entry->fresh(['enteredBy:id,name', 'order:id,order_no', 'vehicle:id,vehicle_number']) ?? $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateExpense(CompanyTransportLedgerEntry $entry, User $actor, array $payload): CompanyTransportLedgerEntry
    {
        if (! $entry->isExpense()) {
            throw ValidationException::withMessages([
                'entry' => ['Only transport expense entries can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($entry, $actor, $payload): CompanyTransportLedgerEntry {
            /** @var CompanyTransportLedgerEntry $locked */
            $locked = CompanyTransportLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $old = $this->snapshot($locked);
            $normalized = $this->normalizeExpensePayload($payload, $actor, $locked);
            $normalized['updated_by'] = $actor->id;
            $locked->update($normalized);
            $fresh = $locked->fresh() ?? $locked;
            $this->audit($fresh, $actor, 'updated', $old, $this->snapshot($fresh));

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     total_collected: float,
     *     total_expense: float,
     *     current_balance: float,
     *     total_collected_label: string,
     *     total_expense_label: string,
     *     current_balance_label: string
     * }
     */
    public function summary(array $filters = []): array
    {
        $query = $this->filteredQuery($filters);
        $collected = (float) (clone $query)
            ->where('entry_kind', CompanyTransportEntryKind::Credit)
            ->sum('credit_amount');
        $creditReversals = (float) (clone $query)
            ->where('entry_kind', CompanyTransportEntryKind::Reversal)
            ->sum('debit_amount');
        $expense = (float) (clone $query)
            ->where('entry_kind', CompanyTransportEntryKind::Debit)
            ->where('source', CompanyTransportLedgerSource::Expense)
            ->sum('debit_amount');

        $totalCollected = round(max(0, $collected - $creditReversals), 2);
        $totalExpense = round($expense, 2);
        $balance = round($totalCollected - $totalExpense, 2);

        return [
            'total_collected' => $totalCollected,
            'total_expense' => $totalExpense,
            'current_balance' => $balance,
            'total_collected_label' => IndianCurrency::formatExact($totalCollected),
            'total_expense_label' => IndianCurrency::formatExact($totalExpense),
            'current_balance_label' => IndianCurrency::formatExact($balance),
        ];
    }

    /**
     * Unfiltered live company position (Admin + mobile cards).
     *
     * @return array<string, mixed>
     */
    public function liveSummary(): array
    {
        return $this->summary([]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<CompanyTransportLedgerEntry>
     */
    public function ledgerRows(array $filters = []): array
    {
        $entries = $this->filteredQuery($filters)
            ->with(['enteredBy:id,name', 'updatedBy:id,name', 'order:id,order_no'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $running = 0.0;
        foreach ($entries as $entry) {
            $running = round($running + $entry->signedAmount(), 2);
            $entry->setAttribute('running_balance', $running);
        }

        return $entries->all();
    }

    /**
     * Dispatched sales orders whose transport type belongs on this ledger.
     */
    public function eligibleRelatedOrdersQuery(): Builder
    {
        $chargeTypes = [
            TransportChargeType::CompanyTransport->value,
            TransportChargeType::TransportExtra->value,
        ];

        return Order::query()
            ->with(['dealer:id,firm_name'])
            ->where('status', Order::STATUS_DISPATCHED)
            ->where(function (Builder $query) use ($chargeTypes): void {
                $query->whereIn('transport_charge_type', $chargeTypes)
                    ->orWhereIn('transport_type', [...$chargeTypes, 'outside_transport']);
            });
    }

    public function isEligibleRelatedOrder(Order $order): bool
    {
        if ($order->status !== Order::STATUS_DISPATCHED) {
            return false;
        }

        return $this->resolveChargeType($order) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchRelatedOrders(?string $search = null, ?string $orderDate = null, int $limit = 30): array
    {
        $orders = $this->eligibleRelatedOrdersQuery()
            ->when(
                filled($orderDate),
                fn (Builder $query) => $query->whereDate('order_date', $orderDate),
            )
            ->when(
                filled($search),
                function (Builder $query) use ($search): void {
                    $term = '%'.trim((string) $search).'%';
                    $vehicleTerm = '%'.Vehicle::normalizeVehicleNumber((string) $search).'%';
                    $query->where(function (Builder $inner) use ($term, $vehicleTerm): void {
                        $inner->where('vehicle_number', 'like', $term)
                            ->orWhere('vehicle_number', 'like', $vehicleTerm)
                            ->orWhereHas(
                                'dealer',
                                fn (Builder $dealer) => $dealer->where('firm_name', 'like', $term),
                            );
                    });
                },
            )
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return $orders->map(fn (Order $order): array => $this->presentRelatedOrder($order))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRelatedOrder(Order $order): array
    {
        $dateLabel = $order->order_date?->format('d M Y') ?: '—';
        $vehicleNumber = filled($order->vehicle_number) ? (string) $order->vehicle_number : '—';
        $dealerName = $order->dealer?->firm_name ?: '—';

        return [
            'id' => $order->id,
            'order_date' => $order->order_date?->toDateString(),
            'order_date_label' => $dateLabel,
            'vehicle_number' => filled($order->vehicle_number) ? (string) $order->vehicle_number : null,
            'dealer_name' => $dealerName,
            'label' => $dateLabel.' | '.$vehicleNumber.' | '.$dealerName,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function presentLedger(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $rows = $this->ledgerRows($filters);
        $total = count($rows);
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'summary' => $this->liveSummary(),
            'filtered_summary' => $this->summary($filters),
            'entries' => array_map(fn (CompanyTransportLedgerEntry $entry): array => $this->presentEntry($entry), $slice),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
            'filters' => $filters,
            'lookups' => [
                'expense_types' => CompanyTransportExpenseType::options(),
                'payment_modes' => CompanyTransportPaymentMode::options(),
                'transport_types' => TransportChargeType::options(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentEntry(CompanyTransportLedgerEntry $entry, bool $includeAudits = false): array
    {
        $running = $entry->getAttribute('running_balance');

        $payload = [
            'id' => $entry->id,
            'transaction_date' => $entry->transaction_date?->toDateString(),
            'transaction_date_label' => $entry->transaction_date?->format('d M Y'),
            'entry_kind' => $entry->entry_kind?->value,
            'entry_kind_label' => $entry->entry_kind?->label(),
            'source' => $entry->source?->value,
            'particulars' => $entry->particulars,
            'order_id' => $entry->order_id,
            'order_no' => $entry->order_no,
            'transport_charge_type' => $entry->transport_charge_type,
            'transport_type_label' => $entry->transportTypeLabel(),
            'vehicle_id' => $entry->vehicle_id,
            'vehicle_number' => $entry->vehicle_number,
            'expense_type' => $entry->expense_type?->value,
            'expense_type_label' => $entry->expenseTypeLabel(),
            'expense_other_description' => $entry->expense_other_description,
            'paid_to' => $entry->paid_to,
            'payment_mode' => $entry->payment_mode?->value,
            'payment_mode_label' => $entry->paymentModeLabel(),
            'amount' => round((float) $entry->amount, 2),
            'debit_amount' => round((float) $entry->debit_amount, 2),
            'credit_amount' => round((float) $entry->credit_amount, 2),
            'debit_label' => (float) $entry->debit_amount > 0.004
                ? IndianCurrency::formatExact((float) $entry->debit_amount)
                : '',
            'credit_label' => (float) $entry->credit_amount > 0.004
                ? IndianCurrency::formatExact((float) $entry->credit_amount)
                : '',
            'running_balance' => $running !== null ? round((float) $running, 2) : null,
            'running_balance_label' => $running !== null ? IndianCurrency::formatExact((float) $running) : null,
            'remark' => $entry->remark,
            'attachment_path' => $entry->attachment_path,
            'attachment_url' => $entry->attachmentUrl(),
            'entered_by' => $entry->entered_by,
            'entered_by_name' => $entry->enteredBy?->name,
            'entered_by_role' => $entry->entered_by_role,
            'updated_by' => $entry->updated_by,
            'updated_by_name' => $entry->updatedBy?->name,
            'created_at' => $entry->created_at?->timezone('Asia/Kolkata')?->toDateTimeString(),
            'updated_at' => $entry->updated_at?->timezone('Asia/Kolkata')?->toDateTimeString(),
            'is_expense' => $entry->isExpense(),
            'is_system_credit' => $entry->isSystemCredit(),
            'is_reversed' => $entry->isReversed(),
            'can_edit' => $entry->isExpense(),
        ];

        if ($includeAudits) {
            $entry->loadMissing(['audits.actor:id,name']);
            $payload['audits'] = $entry->audits->map(fn (CompanyTransportLedgerEntryAudit $audit): array => [
                'id' => $audit->id,
                'action' => $audit->action,
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'actor_id' => $audit->actor_id,
                'actor_name' => $audit->actor?->name,
                'actor_role' => $audit->actor_role,
                'acted_at' => $audit->acted_at?->timezone('Asia/Kolkata')?->toDateTimeString(),
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters = []): Builder
    {
        return CompanyTransportLedgerEntry::query()
            ->when(
                filled($filters['from'] ?? null),
                fn (Builder $q) => $q->whereDate('transaction_date', '>=', $filters['from']),
            )
            ->when(
                filled($filters['to'] ?? null),
                fn (Builder $q) => $q->whereDate('transaction_date', '<=', $filters['to']),
            )
            ->when(
                filled($filters['vehicle_no'] ?? $filters['vehicle_number'] ?? null),
                function (Builder $q) use ($filters): void {
                    $term = Vehicle::normalizeVehicleNumber((string) ($filters['vehicle_no'] ?? $filters['vehicle_number']));
                    $q->where('vehicle_number', 'like', '%'.$term.'%');
                },
            )
            ->when(
                filled($filters['order_no'] ?? null),
                fn (Builder $q) => $q->where('order_no', 'like', '%'.trim((string) $filters['order_no']).'%'),
            )
            ->when(
                filled($filters['expense_type'] ?? null),
                fn (Builder $q) => $q->where('expense_type', $filters['expense_type']),
            )
            ->when(
                filled($filters['transport_charge_type'] ?? $filters['transport_type'] ?? null),
                fn (Builder $q) => $q->where(
                    'transport_charge_type',
                    $filters['transport_charge_type'] ?? $filters['transport_type'],
                ),
            );
    }

    public function storeAttachment(UploadedFile $file): string
    {
        return str_replace('\\', '/', $file->store(self::ATTACHMENT_DIRECTORY, 'public'));
    }

    private function desiredTransportCredit(Order $order): float
    {
        if ($order->status !== Order::STATUS_DISPATCHED) {
            return 0.0;
        }

        $type = $this->resolveChargeType($order);
        if ($type === null) {
            return 0.0;
        }

        return round(max(0, (float) $order->transport_amount), 2);
    }

    private function resolveChargeType(Order $order): ?TransportChargeType
    {
        return TransportChargeType::tryNormalize(
            filled($order->transport_charge_type) ? (string) $order->transport_charge_type : null,
        ) ?? TransportChargeType::tryNormalize(
            filled($order->transport_type) ? (string) $order->transport_type : null,
        );
    }

    private function currentCreditForOrder(int $orderId): ?CompanyTransportLedgerEntry
    {
        return CompanyTransportLedgerEntry::query()
            ->where('order_id', $orderId)
            ->where('entry_kind', CompanyTransportEntryKind::Credit)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->first();
    }

    private function postCredit(
        Order $order,
        User $actor,
        float $amount,
        TransportChargeType $type,
        ?int $vehicleId,
        ?string $vehicleNumber,
    ): CompanyTransportLedgerEntry {
        $date = $order->dispatch_date
            ? Carbon::parse($order->dispatch_date, 'Asia/Kolkata')->toDateString()
            : Carbon::now('Asia/Kolkata')->toDateString();

        $label = $type->label();
        $entry = CompanyTransportLedgerEntry::query()->create([
            'transaction_date' => $date,
            'entry_kind' => CompanyTransportEntryKind::Credit,
            'source' => CompanyTransportLedgerSource::OrderDispatch,
            'particulars' => $label.' — '.$order->order_no,
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'transport_charge_type' => $type->value,
            'vehicle_id' => $vehicleId,
            'vehicle_number' => $vehicleNumber,
            'amount' => $amount,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'remark' => 'System credit from dispatched sales order ('.$label.')',
            'entered_by' => $actor->id,
            'entered_by_role' => $this->actorRole($actor),
        ]);

        $this->audit($entry, $actor, 'created', null, $this->snapshot($entry));

        return $entry;
    }

    private function reverseCredit(
        CompanyTransportLedgerEntry $credit,
        Order $order,
        User $actor,
        string $remark,
    ): void {
        $amount = round((float) $credit->credit_amount, 2);
        $label = $credit->transportTypeLabel() ?: 'Transport';
        $reversal = CompanyTransportLedgerEntry::query()->create([
            'transaction_date' => Carbon::now('Asia/Kolkata')->toDateString(),
            'entry_kind' => CompanyTransportEntryKind::Reversal,
            'source' => CompanyTransportLedgerSource::OrderCorrection,
            'particulars' => 'Reversal of '.$label.' — '.$order->order_no,
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'transport_charge_type' => $credit->transport_charge_type,
            'vehicle_id' => $credit->vehicle_id,
            'vehicle_number' => $credit->vehicle_number,
            'amount' => $amount,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'remark' => $remark,
            'entered_by' => $actor->id,
            'entered_by_role' => $this->actorRole($actor),
            'reversed_entry_id' => $credit->id,
        ]);

        $old = $this->snapshot($credit);
        $credit->update([
            'reversed_at' => Carbon::now('Asia/Kolkata'),
            'updated_by' => $actor->id,
        ]);
        $this->audit($credit->fresh() ?? $credit, $actor, 'reversed', $old, $this->snapshot($credit->fresh() ?? $credit));
        $this->audit($reversal, $actor, 'created', null, $this->snapshot($reversal));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeExpensePayload(array $payload, User $actor, ?CompanyTransportLedgerEntry $existing = null): array
    {
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Enter a valid transport expense amount.'],
            ]);
        }

        $date = $payload['transaction_date'] ?? $payload['date'] ?? Carbon::now('Asia/Kolkata')->toDateString();
        $expenseType = CompanyTransportExpenseType::tryFrom((string) ($payload['expense_type'] ?? ''));
        if ($expenseType === null) {
            throw ValidationException::withMessages([
                'expense_type' => ['Select a valid expense type.'],
            ]);
        }

        $paymentMode = CompanyTransportPaymentMode::tryFrom((string) ($payload['payment_mode'] ?? ''));
        if ($paymentMode === null) {
            throw ValidationException::withMessages([
                'payment_mode' => ['Select a valid payment mode.'],
            ]);
        }

        $vehicleId = isset($payload['vehicle_id']) && filled($payload['vehicle_id'])
            ? (int) $payload['vehicle_id']
            : null;
        $vehicleNumber = filled($payload['vehicle_number'] ?? $payload['vehicle_no'] ?? null)
            ? Vehicle::normalizeVehicleNumber((string) ($payload['vehicle_number'] ?? $payload['vehicle_no']))
            : null;

        if ($vehicleId !== null) {
            $vehicle = Vehicle::query()->find($vehicleId);
            if ($vehicle === null) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['Select a valid vehicle.'],
                ]);
            }
            $vehicleNumber = $vehicle->vehicle_number;
        }

        $orderId = isset($payload['order_id']) && filled($payload['order_id'])
            ? (int) $payload['order_id']
            : null;
        $orderNo = filled($payload['order_no'] ?? null) ? trim((string) $payload['order_no']) : null;
        $relatedType = null;
        if ($orderId !== null) {
            $related = Order::query()->with('dealer:id,firm_name')->find($orderId);
            if ($related === null) {
                throw ValidationException::withMessages([
                    'order_id' => ['Select a valid related order.'],
                ]);
            }
            $keepExisting = $existing !== null && (int) ($existing->order_id ?? 0) === $orderId;
            if (! $keepExisting && ! $this->isEligibleRelatedOrder($related)) {
                throw ValidationException::withMessages([
                    'order_id' => ['Select a dispatched sales order with Company Transport or Transport Charges Extra.'],
                ]);
            }
            $orderNo = $related->order_no;
            $relatedType = $this->resolveChargeType($related);
        } elseif (filled($orderNo)) {
            $related = Order::query()->where('order_no', $orderNo)->first();
            if ($related === null) {
                throw ValidationException::withMessages([
                    'order_id' => ['Select a valid related order.'],
                ]);
            }
            $keepExisting = $existing !== null && (int) ($existing->order_id ?? 0) === (int) $related->id;
            if (! $keepExisting && ! $this->isEligibleRelatedOrder($related)) {
                throw ValidationException::withMessages([
                    'order_id' => ['Select a dispatched sales order with Company Transport or Transport Charges Extra.'],
                ]);
            }
            $orderId = $related->id;
            $orderNo = $related->order_no;
            $relatedType = $this->resolveChargeType($related);
        }

        $otherDescription = null;
        if ($expenseType === CompanyTransportExpenseType::Other) {
            $otherDescription = trim((string) ($payload['expense_other_description'] ?? ''));
            if ($otherDescription === '') {
                throw ValidationException::withMessages([
                    'expense_other_description' => ['Specify the other expense type.'],
                ]);
            }
        }

        $attachmentPath = $existing?->attachment_path;
        if (($payload['attachment'] ?? null) instanceof UploadedFile) {
            $attachmentPath = $this->storeAttachment($payload['attachment']);
        } elseif (array_key_exists('attachment_path', $payload)) {
            $attachmentPath = filled($payload['attachment_path'])
                ? PublicMediaUrl::normalizePublicPath((string) $payload['attachment_path'])
                : null;
        }

        $paidTo = filled($payload['paid_to'] ?? null) ? trim((string) $payload['paid_to']) : null;
        $remark = filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null;
        $particulars = 'Transport Expense — '.$expenseType->label();
        if ($expenseType === CompanyTransportExpenseType::Other && filled($otherDescription)) {
            $particulars = 'Transport Expense — Other: '.$otherDescription;
        }
        if (filled($paidTo)) {
            $particulars .= ' ('.$paidTo.')';
        }

        return [
            'transaction_date' => $date,
            'entry_kind' => CompanyTransportEntryKind::Debit,
            'source' => CompanyTransportLedgerSource::Expense,
            'particulars' => $particulars,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'transport_charge_type' => $relatedType?->value,
            'vehicle_id' => $vehicleId,
            'vehicle_number' => $vehicleNumber,
            'expense_type' => $expenseType,
            'expense_other_description' => $otherDescription,
            'paid_to' => $paidTo,
            'payment_mode' => $paymentMode,
            'amount' => $amount,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'remark' => $remark,
            'attachment_path' => $attachmentPath,
            'entered_by' => $existing?->entered_by ?? $actor->id,
            'entered_by_role' => $existing?->entered_by_role ?? $this->actorRole($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CompanyTransportLedgerEntry $entry): array
    {
        return [
            'transaction_date' => $entry->transaction_date?->toDateString(),
            'entry_kind' => $entry->entry_kind?->value,
            'particulars' => $entry->particulars,
            'order_no' => $entry->order_no,
            'transport_charge_type' => $entry->transport_charge_type,
            'vehicle_number' => $entry->vehicle_number,
            'expense_type' => $entry->expense_type?->value,
            'expense_other_description' => $entry->expense_other_description,
            'paid_to' => $entry->paid_to,
            'payment_mode' => $entry->payment_mode?->value,
            'amount' => round((float) $entry->amount, 2),
            'debit_amount' => round((float) $entry->debit_amount, 2),
            'credit_amount' => round((float) $entry->credit_amount, 2),
            'remark' => $entry->remark,
            'attachment_path' => $entry->attachment_path,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        CompanyTransportLedgerEntry $entry,
        User $actor,
        string $action,
        ?array $old,
        ?array $new,
    ): void {
        CompanyTransportLedgerEntryAudit::query()->create([
            'entry_id' => $entry->id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'actor_id' => $actor->id,
            'actor_role' => $this->actorRole($actor),
            'acted_at' => Carbon::now('Asia/Kolkata'),
        ]);
    }

    private function actorRole(User $actor): string
    {
        if (filled($actor->job_role)) {
            return (string) $actor->job_role;
        }

        return $actor->roleEnum()->label();
    }

    private function backfillActor(Order $order): ?User
    {
        if (filled($order->dispatched_by)) {
            $actor = User::query()->find($order->dispatched_by);
            if ($actor !== null) {
                return $actor;
            }
        }

        return User::query()->where('job_role', 'Admin')->orderBy('id')->first()
            ?? User::query()->where('role', UserRole::ProductionSupervisor->value)->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();
    }
}
