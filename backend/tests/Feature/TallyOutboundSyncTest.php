<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Models\Employee;
use App\Models\Order;
use App\Models\TallyConnectorLedger;
use App\Models\TallyDealerMapping;
use App\Models\TallyLiveSyncState;
use App\Models\TallyOutboundVoucher;
use App\Models\User;
use App\Services\Auth\MobileSessionService;
use App\Services\TallySync\TallyConnectorAuth;
use App\Services\TallySync\TallyDealerMappingService;
use App\Services\TallySync\TallyLiveBalanceService;
use App\Services\TallySync\TallyOutboundEnqueueService;
use Illuminate\Support\Carbon;

function tallySyncEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Tally Sync '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad(substr($mobile, -12), 12, '4', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '3', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

function tallySyncDealer(Employee $employee, array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'Tally Sync Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'gst_no' => '27ABCDE1234F1Z5',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'opening_balance' => 0,
    ], $overrides));
}

function tallySyncMapDealer(Dealer $dealer, string $tallyLedgerName): TallyDealerMapping
{
    return TallyDealerMapping::query()->create([
        'tally_ledger_name' => $tallyLedgerName,
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName($tallyLedgerName),
        'dealer_id' => $dealer->id,
    ]);
}

function tallySyncPendingOrder(Dealer $dealer, Employee $employee, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => '2026-08-20',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_FOR_BILLING,
        'payment_type' => 'Credit',
        'subtotal' => 10000,
        'discount_amount' => 0,
        'gst_amount' => 1800,
        'round_off' => 0,
        'grand_total' => 11800,
    ], $overrides));
}

function tallySyncPendingCollection(Dealer $dealer, Employee $employee, array $overrides = []): Collection
{
    $suffix = (string) random_int(1000, 999999);

    return Collection::query()->create(array_merge([
        'receipt_no' => 'RCP-TS-'.$suffix,
        'collection_date' => '2026-08-21',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 5000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'UPI',
        'transaction_number' => 'TXN-TS-'.$suffix,
        'bank_name' => 'HDFC Bank',
    ], $overrides));
}

function tallySyncConnectorUser(): User
{
    return User::query()->create([
        'name' => 'Tally Connector Owner',
        'email' => 'tally.connector.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function tallySyncConnectorToken(User $user): string
{
    return $user->createToken(
        TallyConnectorAuth::TOKEN_NAME,
        [TallyConnectorAuth::ABILITY],
    )->plainTextToken;
}

it('queues one sales voucher when an order is marked billed', function (): void {
    $employee = tallySyncEmployee('9813000001');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    tallySyncMapDealer($dealer, 'Ganesh Traders - Tally');
    $order = tallySyncPendingOrder($dealer, $employee);

    $order->markAsBilled(
        userId: tallySyncConnectorUser()->id,
        billPath: 'order-bills/tally-test.pdf',
        billNumber: 'BILL-TALLY-1',
        billDate: '2026-08-29',
    );

    $voucher = TallyOutboundVoucher::query()
        ->where('source_type', TallyOutboundVoucher::SOURCE_SALES_ORDER)
        ->where('source_id', $order->id)
        ->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->erp_reference)->toBe('ERP-SO-'.$order->id)
        ->and($voucher->voucher_type)->toBe(TallyOutboundVoucher::VOUCHER_SALES)
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->last_error)->toBeNull()
        ->and($voucher->payload['party']['tally_ledger_name'])->toBe('Ganesh Traders - Tally')
        ->and($voucher->payload['party']['firm_name'])->toBe('Shree Ganesh Traders')
        ->and($voucher->payload['order']['bill_number'])->toBe('BILL-TALLY-1')
        ->and($voucher->payload['order']['grand_total'])->toEqual(11800.0)
        ->and(DealerTallyEntry::query()->where('source_id', $order->id)->count())->toBe(1);

    $order->update(['grand_total' => 99999]);
    app(TallyOutboundEnqueueService::class)->queueBilledOrder($order->fresh());

    expect(TallyOutboundVoucher::query()->where('source_id', $order->id)->count())->toBe(1)
        ->and($voucher->fresh()->payload['order']['grand_total'])->toEqual(11800.0)
        ->and((float) $order->fresh()->grand_total)->toBe(99999.0);
});

it('keeps an unmapped billed order as a failed outbox row without guessing a ledger', function (): void {
    $employee = tallySyncEmployee('9813000002');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Unmapped Firm']);
    $order = tallySyncPendingOrder($dealer, $employee);

    $order->markAsBilled(
        billPath: 'order-bills/unmapped.pdf',
        billNumber: 'BILL-UNMAPPED',
        billDate: '2026-08-29',
    );

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-SO-'.$order->id)->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and($voucher->last_error)->toBe(TallyOutboundEnqueueService::ERROR_NO_MAPPING)
        ->and($voucher->payload['party']['tally_ledger_name'])->toBeNull()
        ->and($voucher->payload['party']['firm_name'])->toBe('Unmapped Firm');
});

it('queues one receipt voucher when a collection is marked received', function (): void {
    $employee = tallySyncEmployee('9813000003');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Collection Party Ledger');
    $collection = tallySyncPendingCollection($dealer, $employee);

    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()
        ->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->erp_reference)->toBe('ERP-COL-'.$collection->id)
        ->and($voucher->voucher_type)->toBe(TallyOutboundVoucher::VOUCHER_RECEIPT)
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->payload['party']['tally_ledger_name'])->toBe('Collection Party Ledger')
        ->and($voucher->payload['collection']['amount'])->toEqual(5000.0)
        ->and($voucher->payload['collection']['payment_mode'])->toBe('UPI')
        ->and($voucher->payload['collection']['receipt_no'])->toBe($collection->receipt_no);

    $collection->update(['amount' => 1]);
    app(TallyOutboundEnqueueService::class)->queueReceivedCollection($collection->fresh());

    expect(TallyOutboundVoucher::query()->where('source_id', $collection->id)->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->count())->toBe(1)
        ->and($voucher->fresh()->payload['collection']['amount'])->toEqual(5000.0);
});

it('keeps an unmapped received collection as a failed outbox row', function (): void {
    $employee = tallySyncEmployee('9813000004');
    $dealer = tallySyncDealer($employee);
    $collection = tallySyncPendingCollection($dealer, $employee);

    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and($voucher->last_error)->toBe(TallyOutboundEnqueueService::ERROR_NO_MAPPING)
        ->and($voucher->payload['party']['tally_ledger_name'])->toBeNull();
});

it('does not enqueue a sales voucher when an order is created already dispatched', function (): void {
    $employee = tallySyncEmployee('9813000005');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Dispatched Party');

    $order = tallySyncPendingOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'dispatch_date' => '2026-08-22',
    ]);

    expect(TallyOutboundVoucher::query()->where('source_id', $order->id)->count())->toBe(0)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $order->id)->count())->toBe(1);
});

it('rejects unauthenticated tally connector requests', function (): void {
    $this->getJson('/api/tally-connector/pending')->assertUnauthorized();
    $this->getJson('/api/tally-connector/heartbeat')->assertUnauthorized();
});

it('rejects sanctum actingAs without a connector token', function (): void {
    $user = tallySyncConnectorUser();

    $this->actingAs($user, 'sanctum')->getJson('/api/tally-connector/pending')->assertForbidden();
});

it('rejects mobile session tokens for the tally API', function (): void {
    $user = tallySyncConnectorUser();
    $mobileToken = $user->createToken(MobileSessionService::TOKEN_NAME, [MobileSessionService::TOKEN_NAME])->plainTextToken;

    $this->withToken($mobileToken)->getJson('/api/tally-connector/pending')->assertForbidden();
});

it('authenticates the tally connector heartbeat without querying vouchers', function (): void {
    $user = tallySyncConnectorUser();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->withHeaders(['X-Tally-Connector-Id' => 'office-pc'])
        ->getJson('/api/tally-connector/heartbeat?tally_company='.urlencode('Param Gold Agro'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Tally connector authenticated.')
        ->assertJsonPath('connector_id', 'office-pc');

    $state = TallyLiveSyncState::current();
    expect($state->last_heartbeat_at)->not->toBeNull()
        ->and($state->connector_id)->toBe('office-pc')
        ->and($state->tally_company)->toBe('Param Gold Agro')
        ->and($state->tally_online)->toBeFalse()
        ->and($state->last_balance_sync_at)->toBeNull();
});

it('stores connector heartbeat without changing tally online or live balance sync', function (): void {
    $syncedAt = Carbon::parse('2026-09-12 10:00:00', 'Asia/Kolkata');
    TallyLiveSyncState::query()->create([
        'connector_id' => 'old-pc',
        'tally_online' => true,
        'last_seen_at' => $syncedAt,
        'last_balance_sync_at' => $syncedAt,
        'last_matched_count' => 11,
    ]);
    $user = tallySyncConnectorUser();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->withHeaders(['X-Tally-Connector-Id' => 'office-pc'])
        ->getJson('/api/tally-connector/heartbeat')
        ->assertOk();

    $state = TallyLiveSyncState::current();
    expect($state->tally_online)->toBeTrue()
        ->and($state->last_heartbeat_at)->not->toBeNull()
        ->and($state->connector_id)->toBe('office-pc')
        ->and($state->last_balance_sync_at?->equalTo($syncedAt))->toBeTrue()
        ->and($state->last_seen_at?->equalTo($syncedAt))->toBeTrue();
});

it('claims and marks a pending sales voucher as synced', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000006');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'API Party');
    $order = tallySyncPendingOrder($dealer, $employee);
    $order->markAsBilled(billPath: 'order-bills/api.pdf', billNumber: 'BILL-API', billDate: '2026-08-29');
    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-SO-'.$order->id)->firstOrFail();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->getJson('/api/tally-connector/pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $voucher->id)
        ->assertJsonPath('data.0.erp_reference', 'ERP-SO-'.$order->id)
        ->assertJsonPath('data.0.payload.party.tally_ledger_name', 'API Party');

    $this->withToken($token)
        ->postJson('/api/tally-connector/vouchers/'.$voucher->id.'/claim', [
            'connector_id' => 'office-pc-1',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', TallyOutboundVoucher::STATUS_CLAIMED);

    $this->withToken($token)
        ->postJson('/api/tally-connector/vouchers/'.$voucher->id.'/synced', [
            'tally_voucher_no' => 'SL-9001',
            'tally_master_id' => 'remote-1',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', TallyOutboundVoucher::STATUS_SYNCED)
        ->assertJsonPath('data.tally_voucher_no', 'SL-9001');

    expect($voucher->fresh()->payload['order']['bill_number'])->toBe('BILL-API');

    $ledger = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $order->id)
        ->first();
    expect($ledger?->tally_voucher_no)->toBe('SL-9001')
        ->and($ledger?->tally_master_id)->toBe('remote-1')
        ->and($ledger?->erp_reference)->toBe(DealerTallyEntry::salesErpReference((int) $order->id));
});

it('omits failed unmapped vouchers from pending and rejects claim', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000007');
    $dealer = tallySyncDealer($employee);
    $order = tallySyncPendingOrder($dealer, $employee);
    $order->markAsBilled(billPath: 'order-bills/fail.pdf', billDate: '2026-08-29');
    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-SO-'.$order->id)->firstOrFail();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->getJson('/api/tally-connector/pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($token)
        ->postJson('/api/tally-connector/vouchers/'.$voucher->id.'/claim')
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', TallyOutboundEnqueueService::ERROR_NO_MAPPING);
});

it('records a connector failure without changing the payload snapshot', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000008');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Fail Party');
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);
    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->firstOrFail();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/vouchers/'.$voucher->id.'/failed', [
            'error' => 'Tally company is not open.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', TallyOutboundVoucher::STATUS_FAILED)
        ->assertJsonPath('data.last_error', 'Tally company is not open.');

    expect($voucher->fresh()->payload['collection']['amount'])->toEqual(5000.0);
});

it('withdraws an unsynced receipt if the collection leaves received', function (): void {
    $employee = tallySyncEmployee('9813000010');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Withdraw Party');
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->firstOrFail();
    expect($voucher->status)->toBe(TallyOutboundVoucher::STATUS_PENDING);

    $collection->update(['status' => Collection::STATUS_NOT_RECEIVED, 'admin_remark' => 'Cheque bounced']);

    expect($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and($voucher->fresh()->last_error)->toBe('Collection is no longer Received, so it must not be sent to Tally.')
        ->and(TallyOutboundVoucher::query()->where('source_id', $collection->id)->count())->toBe(1);
});

it('returns expired claims in pending so the connector can retry', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000009');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Retry Party');
    $order = tallySyncPendingOrder($dealer, $employee);
    $order->markAsBilled(billPath: 'order-bills/retry.pdf', billDate: '2026-08-29');
    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-SO-'.$order->id)->firstOrFail();
    $voucher->update([
        'status' => TallyOutboundVoucher::STATUS_CLAIMED,
        'claimed_at' => Carbon::now()->subMinutes(10),
        'claimed_until' => Carbon::now()->subMinute(),
        'claimed_by' => 'stale-pc',
    ]);

    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->getJson('/api/tally-connector/pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $voucher->id);
});

it('queues a receipt using the dealer saved tally guid mapping', function (): void {
    $employee = tallySyncEmployee('9813000150');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'ERP Collection Dealer']);
    TallyDealerMapping::query()->create([
        'tally_ledger_name' => 'Mapped Collection Party',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Mapped Collection Party'),
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-111111111111',
        'dealer_id' => $dealer->id,
    ]);
    $collection = tallySyncPendingCollection($dealer, $employee);

    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->first();
    $status = app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh());

    expect($voucher)->not->toBeNull()
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->payload['party']['tally_ledger_name'])->toBe('Mapped Collection Party')
        ->and($voucher->payload['party']['tally_ledger_guid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-111111111111')
        ->and($status['label'])->toBe('Pending');
});

it('queues a receipt from live exact-name mapping when no mapping row exists', function (): void {
    $employee = tallySyncEmployee('9813000151');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Live Exact Collection Agro']);
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 0,
        'opening_balance_type' => 'debit',
        'live_closing_balance' => 100,
        'live_closing_balance_type' => 'debit',
        'live_tally_ledger_name' => 'Live Exact Collection Agro',
        'live_tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-222222222222',
        'live_synced_at' => now('Asia/Kolkata'),
        'financial_start_date' => '2026-04-01',
    ]);
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->payload['party']['tally_ledger_name'])->toBe('Live Exact Collection Agro');
});

it('requeues a not-mapped received collection after guid mapping is saved without duplicating', function (): void {
    $employee = tallySyncEmployee('9813000152');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Later Mapped Collection']);
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->firstOrFail();
    expect($voucher->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Not Mapped');

    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-333333333333',
        'tally_ledger_name' => 'Later Mapped Party',
        'tally_ledger_name_normalized' => 'later mapped party',
        'last_seen_at' => now('Asia/Kolkata'),
    ]);
    app(TallyDealerMappingService::class)->assign($dealer, 'aaaaaaaa-bbbb-cccc-dddd-333333333333');

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->fresh()->payload['party']['tally_ledger_name'])->toBe('Later Mapped Party')
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Pending');
});

it('requeues a not-mapped receipt after live tally exact-name sync without duplicating', function (): void {
    $employee = tallySyncEmployee('9813000154');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Live Unstick Collection Agro']);
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);

    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->firstOrFail();
    expect($voucher->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Not Mapped');

    app(TallyLiveBalanceService::class)->ingest('office-pc-1', true, [[
        'tally_ledger_name' => 'Live Unstick Collection Agro',
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-555555555555',
        'closing_balance' => 250,
        'closing_balance_type' => 'debit',
    ]]);

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($voucher->fresh()->payload['party']['tally_ledger_name'])->toBe('Live Unstick Collection Agro')
        ->and($voucher->fresh()->payload['party']['tally_ledger_guid'])->toBe('aaaaaaaa-bbbb-cccc-dddd-555555555555')
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Pending');
});

it('does not reset a tally xml failure when mapping is saved', function (): void {
    $employee = tallySyncEmployee('9813000153');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Tally Error Party');
    $collection = tallySyncPendingCollection($dealer, $employee);
    $collection->transitionTo(Collection::STATUS_RECEIVED);
    $voucher = TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$collection->id)->firstOrFail();
    $voucher->update([
        'status' => TallyOutboundVoucher::STATUS_FAILED,
        'last_error' => 'Could not find ledger Cash',
    ]);

    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-444444444444',
        'tally_ledger_name' => 'Tally Error Party',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Tally Error Party'),
        'last_seen_at' => now('Asia/Kolkata'),
    ]);
    app(TallyDealerMappingService::class)->assign($dealer, 'aaaaaaaa-bbbb-cccc-dddd-444444444444');

    expect($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_FAILED)
        ->and($voucher->fresh()->last_error)->toBe('Could not find ledger Cash')
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Failed')
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['error'])->toBe('Could not find ledger Cash');
});

function tallySyncHistoricalReceivedCollection(Dealer $dealer, Employee $employee): Collection
{
    $collection = tallySyncPendingCollection($dealer, $employee);

    Collection::withoutEvents(function () use ($collection): void {
        $collection->forceFill([
            'status' => Collection::STATUS_RECEIVED,
            'received_at' => null,
        ])->save();
    });

    return $collection->fresh() ?? $collection;
}

it('does not queue a tally receipt for collections already received before 12 sep 2026', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', 'Asia/Kolkata'));
    $employee = tallySyncEmployee('9813000201');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Historical Receipt Party');
    $collection = tallySyncHistoricalReceivedCollection($dealer, $employee);
    $ledgerBefore = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->count();

    app(TallyOutboundEnqueueService::class)->queueReceivedCollection($collection->fresh());
    app(TallyOutboundEnqueueService::class)->requeueReceivedCollectionsForDealer($dealer);

    expect($collection->fresh()->status)->toBe(Collection::STATUS_RECEIVED)
        ->and($collection->fresh()->received_at)->toBeNull()
        ->and($collection->fresh()->amount)->toEqual(5000.0)
        ->and(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyOutboundEnqueueService::class)->postingStatus($collection->fresh())['label'])->toBe('Not sent (before 12 Sep 2026)')
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe($ledgerBefore);

    Carbon::setTestNow();
});

it('does not requeue historical received collections after mapping is saved or live tally sync', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', 'Asia/Kolkata'));
    $employee = tallySyncEmployee('9813000202');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Historical Mapped Collection']);
    $collection = tallySyncHistoricalReceivedCollection($dealer, $employee);

    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-666666666666',
        'tally_ledger_name' => 'Historical Mapped Party',
        'tally_ledger_name_normalized' => 'historical mapped party',
        'last_seen_at' => now('Asia/Kolkata'),
    ]);
    app(TallyDealerMappingService::class)->assign($dealer, 'aaaaaaaa-bbbb-cccc-dddd-666666666666');

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0);

    app(TallyLiveBalanceService::class)->ingest('office-pc-hist', true, [[
        'tally_ledger_name' => 'Historical Mapped Party',
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-666666666666',
        'closing_balance' => 100,
        'closing_balance_type' => 'debit',
    ]]);

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0);

    Carbon::setTestNow();
});

it('skips leftover historical receipt outbox rows so deleted tally vouchers are not resent', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', 'Asia/Kolkata'));
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000203');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Stale Receipt Party');
    $collection = tallySyncHistoricalReceivedCollection($dealer, $employee);

    $voucher = TallyOutboundVoucher::query()->create([
        'source_type' => TallyOutboundVoucher::SOURCE_COLLECTION,
        'source_id' => $collection->id,
        'voucher_type' => TallyOutboundVoucher::VOUCHER_RECEIPT,
        'erp_reference' => TallyOutboundVoucher::receiptReference((int) $collection->id),
        'payload' => ['erp_reference' => TallyOutboundVoucher::receiptReference((int) $collection->id)],
        'status' => TallyOutboundVoucher::STATUS_PENDING,
        'attempts' => 0,
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->getJson('/api/tally-connector/pending')
        ->assertOk()
        ->assertJsonMissing(['id' => $voucher->id]);

    expect($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_SKIPPED)
        ->and(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1);

    app(TallyOutboundEnqueueService::class)->queueReceivedCollection($collection->fresh());

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and($voucher->fresh()->status)->toBe(TallyOutboundVoucher::STATUS_SKIPPED);

    $this->withToken($token)
        ->postJson('/api/tally-connector/vouchers/'.$voucher->id.'/claim')
        ->assertUnprocessable();

    Carbon::setTestNow();
});

it('queues one tally receipt only when a collection is newly marked received on or after 12 sep 2026', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11 23:59:00', 'Asia/Kolkata'));
    $employee = tallySyncEmployee('9813000204');
    $dealer = tallySyncDealer($employee);
    tallySyncMapDealer($dealer, 'Cutoff Receipt Party');
    $tooEarly = tallySyncPendingCollection($dealer, $employee);
    $tooEarly->transitionTo(Collection::STATUS_RECEIVED);

    expect(TallyOutboundVoucher::query()->where('source_id', $tooEarly->id)->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->count())->toBe(0)
        ->and($tooEarly->fresh()->received_at?->timezone('Asia/Kolkata')->toDateString())->toBe('2026-09-11');

    Carbon::setTestNow(Carbon::parse('2026-09-12 00:00:00', 'Asia/Kolkata'));
    app(TallyOutboundEnqueueService::class)->queueReceivedCollection($tooEarly->fresh());
    expect(TallyOutboundVoucher::query()->where('source_id', $tooEarly->id)->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->count())->toBe(0);

    $onCutoff = tallySyncPendingCollection($dealer, $employee);
    $onCutoff->transitionTo(Collection::STATUS_RECEIVED);
    app(TallyOutboundEnqueueService::class)->queueReceivedCollection($onCutoff->fresh());

    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_COLLECTION)->where('source_id', $onCutoff->id)->count())->toBe(1)
        ->and(TallyOutboundVoucher::query()->where('erp_reference', 'ERP-COL-'.$onCutoff->id)->value('status'))->toBe(TallyOutboundVoucher::STATUS_PENDING)
        ->and($onCutoff->fresh()->received_at?->timezone('Asia/Kolkata')->toDateTimeString())->toBe('2026-09-12 00:00:00');

    Carbon::setTestNow();
});

