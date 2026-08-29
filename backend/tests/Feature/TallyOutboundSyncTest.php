<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Employee;
use App\Models\Order;
use App\Models\TallyDealerMapping;
use App\Models\TallyOutboundVoucher;
use App\Models\User;
use App\Services\Auth\MobileSessionService;
use App\Services\TallySync\TallyConnectorAuth;
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
        ->and(DealerTallyEntry::query()->where('source_id', $order->id)->count())->toBe(0);

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
