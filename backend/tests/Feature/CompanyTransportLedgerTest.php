<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Orders\ApplyDispatchedOrderTransportCorrection;
use App\Actions\Orders\ApproveOrderEditPermission;
use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\DispatchOrder;
use App\Actions\Orders\RequestOrderEditPermission;
use App\Actions\Orders\SendOrderForBilling;
use App\Enums\CompanyTransportEntryKind;
use App\Enums\CompanyTransportExpenseType;
use App\Enums\CompanyTransportPaymentMode;
use App\Enums\UserRole;
use App\Models\CompanyTransportLedgerEntry;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Orders\CompanyTransportLedgerService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function ctlEmployee(UserRole $role, string $mobile): \App\Models\Employee
{
    $tail = substr(preg_replace('/\D/', '', $mobile) ?: '0000', -4);

    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' CTL '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.ctl@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '23'.$mobile,
        'pan_number' => 'ABCDE'.$tail.'C',
        'bank_name' => 'Test Bank',
        'account_number' => '123456'.$mobile,
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => $role->value,
    ])->employee;
}

function ctlAdmin(): User
{
    return User::query()->create([
        'name' => 'CTL Admin',
        'email' => 'admin.ctl.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

/**
 * @return array{order: Order, production: \App\Models\Employee, admin: User, vehicle: Vehicle, director: User}
 */
function ctlDispatchedCompanyTransportOrder(float $freight = 40, string $chargeType = 'company_transport'): array
{
    Storage::fake('public');

    $batch = (string) random_int(2000000, 8999999);
    $employee = ctlEmployee(UserRole::Employee, '93'.$batch.'1');
    $manager = ctlEmployee(UserRole::Manager, '93'.$batch.'2');
    $production = ctlEmployee(UserRole::ProductionSupervisor, '93'.$batch.'3');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $admin = ctlAdmin();
    $director = User::query()->create([
        'name' => 'CTL Director',
        'email' => 'director.ctl.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);

    $dealer = Dealer::query()->create([
        'firm_name' => 'CTL Dealer '.$batch,
        'owner_name' => 'Owner',
        'mobile' => '94'.$batch.'1',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $order = Order::query()->create([
        'order_no' => 'SO-CTL-'.random_int(1000, 9999),
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);
    $order->approve($manager->user->id);

    $vehicle = Vehicle::query()->create([
        'vehicle_number' => 'MH12CT'.random_int(1000, 9999),
        'vehicle_name' => 'Tata Ace',
        'is_active' => true,
        'created_by' => $production->user->id,
    ]);

    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleId: $vehicle->id,
        transportChargeType: $chargeType,
        transportFreight: $freight,
    );

    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-CTL-'.$batch,
    );

    return [
        'order' => $order->fresh(),
        'production' => $production,
        'admin' => $admin,
        'vehicle' => $vehicle,
        'director' => $director,
    ];
}

it('does not post company transport credit before dispatch', function () {
    $ctx = ctlDispatchedCompanyTransportOrder();

    expect(CompanyTransportLedgerEntry::query()->count())->toBe(0)
        ->and($ctx['order']->status)->toBe(Order::STATUS_BILLED)
        ->and((float) $ctx['order']->transport_amount)->toBe(40.0);
});

it('posts one company transport credit when an order is dispatched', function () {
    $ctx = ctlDispatchedCompanyTransportOrder();

    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
        remark: 'Loaded',
    );

    $credits = CompanyTransportLedgerEntry::query()
        ->where('entry_kind', CompanyTransportEntryKind::Credit)
        ->get();

    expect($credits)->toHaveCount(1)
        ->and((float) $credits->first()->credit_amount)->toBe(40.0)
        ->and((float) $credits->first()->debit_amount)->toBe(0.0)
        ->and($credits->first()->order_id)->toBe($ctx['order']->id)
        ->and($credits->first()->transport_charge_type)->toBe('company_transport')
        ->and($credits->first()->particulars)->toContain('Company Transport');
});

it('does not duplicate credit when dispatched order is saved again with the same amount', function () {
    $ctx = ctlDispatchedCompanyTransportOrder();
    $order = $ctx['order']->fresh();

    app(DispatchOrder::class)->execute(
        order: $order,
        actor: $ctx['production']->user,
    );

    $service = app(CompanyTransportLedgerService::class);
    $service->syncDispatchedOrderCredit($order->fresh(), $ctx['production']->user);
    $service->syncDispatchedOrderCredit($order->fresh(), $ctx['production']->user);

    expect(CompanyTransportLedgerEntry::query()->where('entry_kind', CompanyTransportEntryKind::Credit)->count())->toBe(1)
        ->and(CompanyTransportLedgerEntry::query()->where('entry_kind', CompanyTransportEntryKind::Reversal)->count())->toBe(0);
});

it('posts transport extra credit on dispatch in the same ledger', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(25, 'transport_extra');

    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
    );

    $credits = CompanyTransportLedgerEntry::query()
        ->where('entry_kind', CompanyTransportEntryKind::Credit)
        ->get();

    expect($credits)->toHaveCount(1)
        ->and((float) $credits->first()->credit_amount)->toBe(25.0)
        ->and($credits->first()->order_id)->toBe($ctx['order']->id)
        ->and($credits->first()->transport_charge_type)->toBe('transport_extra')
        ->and($credits->first()->particulars)->toContain('Transport Charges Extra');
});

it('backfills dispatched orders of both transport types without duplicating on rerun', function () {
    $company = ctlDispatchedCompanyTransportOrder(40, 'company_transport');
    $extra = ctlDispatchedCompanyTransportOrder(80, 'transport_extra');

    $dispatchDate = '2026-08-15';
    foreach ([$company, $extra] as $ctx) {
        $ctx['order']->forceFill([
            'status' => Order::STATUS_DISPATCHED,
            'dispatch_date' => $dispatchDate,
            'dispatched_by' => $ctx['production']->user->id,
        ])->saveQuietly();
    }

    expect(CompanyTransportLedgerEntry::query()->count())->toBe(0);

    $service = app(CompanyTransportLedgerService::class);
    $first = $service->backfillDispatchedOrderCredits();
    $second = $service->backfillDispatchedOrderCredits();

    $credits = CompanyTransportLedgerEntry::query()
        ->where('entry_kind', CompanyTransportEntryKind::Credit)
        ->whereNull('reversed_at')
        ->orderBy('id')
        ->get();

    expect($first['posted'])->toBe(2)
        ->and($second['posted'])->toBe(0)
        ->and($credits)->toHaveCount(2)
        ->and($credits->pluck('order_id')->sort()->values()->all())->toBe([
            $company['order']->id,
            $extra['order']->id,
        ])
        ->and($credits->pluck('transport_charge_type')->sort()->values()->all())->toBe([
            'company_transport',
            'transport_extra',
        ]);

    foreach ($credits as $credit) {
        expect($credit->transaction_date?->toDateString())->toBe($dispatchDate)
            ->and((float) $credit->debit_amount)->toBe(0.0)
            ->and((float) $credit->credit_amount)->toBeGreaterThan(0);
    }

    $summary = $service->liveSummary();
    expect($summary['total_collected'])->toBe(120.0)
        ->and($summary['current_balance'])->toBe(120.0);
});

it('reverses and reposts credit when dispatched company transport is corrected', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(40);

    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
    );

    $requested = app(RequestOrderEditPermission::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['admin'],
        reason: 'Correct company transport amount.',
    )['request'];

    app(ApproveOrderEditPermission::class)->execute($requested->fresh(), $ctx['director']);

    app(ApplyDispatchedOrderTransportCorrection::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['admin'],
        vehicleId: $ctx['vehicle']->id,
        transportChargeType: 'company_transport',
        transportFreight: 55,
    );

    $credits = CompanyTransportLedgerEntry::query()
        ->where('entry_kind', CompanyTransportEntryKind::Credit)
        ->orderBy('id')
        ->get();
    $reversals = CompanyTransportLedgerEntry::query()
        ->where('entry_kind', CompanyTransportEntryKind::Reversal)
        ->get();

    expect($credits)->toHaveCount(2)
        ->and($credits->first()->reversed_at)->not->toBeNull()
        ->and((float) $credits->last()->credit_amount)->toBe(55.0)
        ->and($credits->last()->reversed_at)->toBeNull()
        ->and($reversals)->toHaveCount(1)
        ->and((float) $reversals->first()->debit_amount)->toBe(40.0);

    $summary = app(CompanyTransportLedgerService::class)->liveSummary();
    expect($summary['total_collected'])->toBe(55.0)
        ->and($summary['total_expense'])->toBe(0.0)
        ->and($summary['current_balance'])->toBe(55.0);
});

it('lets production supervisor add a debit expense and keeps admin/mobile summary in sync', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(40);
    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
    );

    $this->actingAs($ctx['production']->user, 'sanctum')
        ->postJson('/api/production/company-transport/expenses', [
            'transaction_date' => now('Asia/Kolkata')->toDateString(),
            'amount' => 15,
            'expense_type' => CompanyTransportExpenseType::Fuel->value,
            'vehicle_id' => $ctx['vehicle']->id,
            'paid_to' => 'HP Petrol Pump',
            'payment_mode' => CompanyTransportPaymentMode::Cash->value,
            'remark' => 'Diesel',
        ])
        ->assertCreated()
        ->assertJsonPath('data.entry_kind', 'debit')
        ->assertJsonPath('data.debit_amount', 15);

    $ledger = $this->actingAs($ctx['production']->user, 'sanctum')
        ->getJson('/api/production/company-transport/ledger')
        ->assertOk()
        ->json('data');

    expect((float) $ledger['summary']['total_collected'])->toBe(40.0)
        ->and((float) $ledger['summary']['total_expense'])->toBe(15.0)
        ->and((float) $ledger['summary']['current_balance'])->toBe(25.0)
        ->and($ledger['entries'])->toHaveCount(2);

    $this->actingAs($ctx['production']->user, 'sanctum')
        ->postJson('/api/production/company-transport/expenses', [
            'amount' => 10,
            'expense_type' => CompanyTransportExpenseType::Toll->value,
            'paid_to' => 'NHAI',
            'payment_mode' => CompanyTransportPaymentMode::Upi->value,
            'entry_kind' => 'credit',
        ])
        ->assertCreated()
        ->assertJsonPath('data.entry_kind', 'debit');

    expect(CompanyTransportLedgerEntry::query()->where('entry_kind', CompanyTransportEntryKind::Credit)->count())->toBe(1);
});

it('blocks production supervisor from editing expenses and never deletes ledger rows', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(40);
    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
    );

    $expense = app(CompanyTransportLedgerService::class)->recordExpense($ctx['production']->user, [
        'amount' => 12,
        'expense_type' => CompanyTransportExpenseType::Toll->value,
        'paid_to' => 'Booth',
        'payment_mode' => CompanyTransportPaymentMode::Cash->value,
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect(\Illuminate\Support\Facades\Gate::forUser($ctx['production']->user)->allows('update', $expense))->toBeFalse();

    expect(\Illuminate\Support\Facades\Gate::forUser($ctx['admin'])->allows('update', $expense))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($ctx['admin'])->allows('delete', $expense))->toBeFalse();

    $updated = app(CompanyTransportLedgerService::class)->updateExpense($expense, $ctx['admin'], [
        'amount' => 18,
        'expense_type' => CompanyTransportExpenseType::Toll->value,
        'paid_to' => 'Booth',
        'payment_mode' => CompanyTransportPaymentMode::Cash->value,
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect((float) $updated->debit_amount)->toBe(18.0)
        ->and($updated->audits()->count())->toBeGreaterThanOrEqual(2)
        ->and(CompanyTransportLedgerEntry::query()->count())->toBe(2);
});

it('searches only dispatched orders with ledger transport types by order no, dealer, and date', function () {
    $company = ctlDispatchedCompanyTransportOrder(40, 'company_transport');
    $extra = ctlDispatchedCompanyTransportOrder(80, 'transport_extra');
    $billed = ctlDispatchedCompanyTransportOrder(25, 'company_transport');

    app(DispatchOrder::class)->execute(
        order: $company['order']->fresh(),
        actor: $company['production']->user,
    );
    app(DispatchOrder::class)->execute(
        order: $extra['order']->fresh(),
        actor: $extra['production']->user,
    );

    $companyOrder = $company['order']->fresh();
    $extraOrder = $extra['order']->fresh();

    $listed = $this->actingAs($company['production']->user, 'sanctum')
        ->getJson('/api/production/company-transport/orders')
        ->assertOk()
        ->json('data');

    $ids = collect($listed)->pluck('id')->all();
    expect($ids)->toContain($companyOrder->id)
        ->and($ids)->toContain($extraOrder->id)
        ->and($ids)->not->toContain($billed['order']->id);

    $byDealer = $this->actingAs($company['production']->user, 'sanctum')
        ->getJson('/api/production/company-transport/orders?search='.urlencode((string) $companyOrder->dealer->firm_name))
        ->assertOk()
        ->json('data');

    expect(collect($byDealer)->pluck('id')->all())->toContain($companyOrder->id);

    $byDate = $this->actingAs($company['production']->user, 'sanctum')
        ->getJson('/api/production/company-transport/orders?order_date='.$companyOrder->order_date->toDateString())
        ->assertOk()
        ->json('data');

    expect(collect($byDate)->pluck('id')->all())->toContain($companyOrder->id);
    expect($listed[0])->toHaveKeys(['id', 'order_no', 'label', 'dealer_name', 'transport_type_label', 'transport_amount_label']);
});

it('links a transport expense to a dispatched order and stores other expense details', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(40);
    app(DispatchOrder::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['production']->user,
    );

    $this->actingAs($ctx['production']->user, 'sanctum')
        ->postJson('/api/production/company-transport/expenses', [
            'amount' => 12,
            'expense_type' => CompanyTransportExpenseType::Other->value,
            'paid_to' => 'Workshop',
            'payment_mode' => CompanyTransportPaymentMode::Cash->value,
        ])
        ->assertUnprocessable();

    $response = $this->actingAs($ctx['production']->user, 'sanctum')
        ->postJson('/api/production/company-transport/expenses', [
            'amount' => 12,
            'expense_type' => CompanyTransportExpenseType::Other->value,
            'expense_other_description' => 'Parking',
            'paid_to' => 'Workshop',
            'payment_mode' => CompanyTransportPaymentMode::Cash->value,
            'order_id' => $ctx['order']->id,
        ])
        ->assertCreated()
        ->json('data');

    expect($response['order_id'])->toBe($ctx['order']->id)
        ->and($response['order_no'])->toBe($ctx['order']->fresh()->order_no)
        ->and($response['expense_other_description'])->toBe('Parking')
        ->and($response['particulars'])->toContain('Parking')
        ->and((float) $response['debit_amount'])->toBe(12.0)
        ->and((float) $response['credit_amount'])->toBe(0.0);

    $summary = app(CompanyTransportLedgerService::class)->liveSummary();
    expect($summary['total_collected'])->toBe(40.0)
        ->and($summary['total_expense'])->toBe(12.0)
        ->and($summary['current_balance'])->toBe(28.0);
});

it('rejects linking a transport expense to a non-dispatched order', function () {
    $ctx = ctlDispatchedCompanyTransportOrder(40);

    $this->actingAs($ctx['production']->user, 'sanctum')
        ->postJson('/api/production/company-transport/expenses', [
            'amount' => 8,
            'expense_type' => CompanyTransportExpenseType::Toll->value,
            'paid_to' => 'Booth',
            'payment_mode' => CompanyTransportPaymentMode::Cash->value,
            'order_id' => $ctx['order']->id,
        ])
        ->assertUnprocessable();
});

