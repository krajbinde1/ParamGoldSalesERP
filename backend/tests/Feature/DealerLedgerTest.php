<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\Dealers\DealerLedgerService;
use App\Support\IndianCurrency;

function ledgerEmployee(UserRole $role, string $mobile, ?int $managerId = null): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
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
        'role' => $role->value,
        'reporting_manager_id' => $managerId,
    ])->employee;
}

function ledgerDealer(Employee $employee, array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'Ledger Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'opening_balance' => 100000,
        'opening_balance_date' => '2026-04-01',
    ], $overrides));
}

function ledgerOrder(Dealer $dealer, Employee $employee, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => '2026-04-10',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 50000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 50000,
    ], $overrides));
}

function ledgerCollection(Dealer $dealer, Employee $employee, array $overrides = []): Collection
{
    $suffix = (string) random_int(1000, 999999);

    return Collection::query()->create(array_merge([
        'receipt_no' => 'RCP-LED-'.$suffix,
        'collection_date' => '2026-04-18',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 20000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-LED-'.$suffix,
    ], $overrides));
}

it('formats indian rupees without raw decimals', function (): void {
    expect(IndianCurrency::format(275000))->toBe('₹2,75,000')
        ->and(IndianCurrency::format(1250000))->toBe('₹12,50,000')
        ->and(IndianCurrency::format(0))->toBe('₹0')
        ->and(IndianCurrency::format(1500.5))->toBe('₹1,500.50');
});

it('posts dispatched sales and received collections into the dealer ledger once', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100001');
    $dealer = ledgerDealer($employee);
    $service = app(DealerLedgerService::class);

    expect($service->getOutstanding($dealer))->toBe(0.0);

    $pending = ledgerOrder($dealer, $employee, ['status' => Order::STATUS_PENDING_APPROVAL, 'grand_total' => 40000]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(0.0);

    $pending->forceFill(['status' => Order::STATUS_APPROVED])->saveQuietly();
    expect($service->getOutstanding($dealer->fresh()))->toBe(0.0)
        ->and($service->getUnbilledOrders($dealer->fresh()))->toBe(40000.0);

    $billed = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_BILLED,
        'grand_total' => 50000,
        'bill_number' => 'BILL-1',
        'bill_date' => '2026-04-15',
        'billed_at' => '2026-04-15 11:00:00',
    ]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(0.0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);

    $pendingCollection = ledgerCollection($dealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_PENDING]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(0.0);

    $pendingCollection->update(['status' => Collection::STATUS_RECEIVED]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(-20000.0)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->count())->toBe(1);

    $pendingCollection->update(['admin_remark' => 're-saved received']);
    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->count())->toBe(1);

    $dispatched = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 50000,
        'order_no' => 'ORD500001',
        'dispatch_date' => '2026-04-25',
        'dispatched_at' => '2026-04-25 11:00:00',
    ]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(30000.0)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $dispatched->id)->count())->toBe(1)
        ->and(DealerTallyEntry::query()->where('source_id', $dispatched->id)->value('voucher_no'))->toBe('ORD500001');

    $dispatched->update(['dispatch_remark' => 'refreshed']);
    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $dispatched->id)->count())->toBe(1);

    $dispatched->update(['grand_total' => 55000]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(35000.0)
        ->and((float) DealerTallyEntry::query()->where('source_id', $dispatched->id)->value('debit'))->toBe(55000.0);

    ledgerCollection($dealer, $employee, [
        'amount' => 5000,
        'status' => Collection::STATUS_NOT_RECEIVED,
    ]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(35000.0);

    $ledger = $service->getLedger($dealer->fresh());
    expect($ledger['summary']['current_outstanding'])->toBe(35000.0)
        ->and(collect($ledger['ledger'])->where('type', 'sales_invoice')->pluck('source_id')->unique()->count())
        ->toBe(collect($ledger['ledger'])->where('type', 'sales_invoice')->count());
});

it('exposes the same account summary and ledger through the api', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100002');
    $dealer = ledgerDealer($employee);
    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 50000,
        'order_no' => 'ORD500002',
        'dispatch_date' => '2026-04-15',
        'dispatched_at' => '2026-04-15 11:00:00',
    ]);
    ledgerCollection($dealer, $employee, [
        'amount' => 20000,
        'status' => Collection::STATUS_RECEIVED,
        'collection_date' => '2026-04-18',
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/dealers/'.$dealer->id.'/account-summary')
        ->assertOk()
        ->assertJsonPath('data.opening_balance', 0)
        ->assertJsonPath('data.current_outstanding', 30000);

    $ledgerResponse = $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/dealers/'.$dealer->id.'/ledger')
        ->assertOk();

    $ledger = $ledgerResponse->json('data.ledger');
    expect($ledger[0]['type'])->toBe('opening_balance')
        ->and($ledger[array_key_last($ledger)]['balance'])->toBe(30000)
        ->and(collect($ledger)->firstWhere('source_id', $order->id)['reference'])->toBe('ORD500002');
});

it('enforces dealer ledger permissions for employee manager and production supervisor', function (): void {
    $manager = ledgerEmployee(UserRole::Manager, '9811100003');
    $teamEmployee = ledgerEmployee(UserRole::Employee, '9811100004', $manager->id);
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9811100005');
    $production = ledgerEmployee(UserRole::ProductionSupervisor, '9811100006');
    $teamDealer = ledgerDealer($teamEmployee);
    $otherDealer = ledgerDealer($otherEmployee);

    $this->actingAs($teamEmployee->user, 'sanctum')
        ->getJson('/api/dealers/'.$teamDealer->id.'/ledger')
        ->assertOk();
    $this->actingAs($teamEmployee->user, 'sanctum')
        ->getJson('/api/dealers/'.$otherDealer->id.'/ledger')
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/dealers/'.$teamDealer->id.'/ledger')
        ->assertOk();
    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/dealers/'.$otherDealer->id.'/ledger')
        ->assertForbidden();

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/dealers/'.$teamDealer->id.'/ledger')
        ->assertForbidden();

    $director = User::query()->create([
        'name' => 'Director Ledger',
        'email' => 'director.ledger.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
    $this->actingAs($director, 'sanctum')
        ->getJson('/api/dealers/'.$otherDealer->id.'/account-summary')
        ->assertOk();
});

it('treats a tally credit opening balance as a credit in outstanding and ledger', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100007');
    $dealer = ledgerDealer($employee, [
        'opening_balance' => 40000,
        'opening_balance_type' => 'credit',
        'opening_balance_date' => '2026-04-01',
    ]);
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 40000,
        'opening_balance_type' => 'credit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
        'last_imported_at' => now(),
    ]);
    $service = app(DealerLedgerService::class);

    expect($service->getOutstanding($dealer))->toBe(-40000.0);

    ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 50000,
        'dispatch_date' => '2026-04-15',
        'dispatched_at' => '2026-04-15 11:00:00',
    ]);
    expect($service->getOutstanding($dealer->fresh()))->toBe(10000.0);

    $ledger = $service->getLedger($dealer->fresh());
    expect($ledger['summary']['opening_balance'])->toBe(40000.0)
        ->and($ledger['summary']['opening_balance_type'])->toBe('credit')
        ->and($ledger['summary']['current_outstanding'])->toBe(10000.0)
        ->and($ledger['ledger'][0]['debit'])->toBe(0.0)
        ->and($ledger['ledger'][0]['credit'])->toBe(40000.0)
        ->and($ledger['ledger'][0]['balance'])->toBe(-40000.0)
        ->and($ledger['ledger'][array_key_last($ledger['ledger'])]['balance'])->toBe(10000.0);
});
