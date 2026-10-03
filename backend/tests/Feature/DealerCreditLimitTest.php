<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Resources\DealerCreditLimits\DealerCreditLimitResource;
use App\Models\AppNotification;
use App\Models\Dealer;
use App\Models\DealerCreditLimitAudit;
use App\Models\DealerTallyEntry;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Dealers\DealerCreditExposureService;
use App\Services\Dealers\DealerCreditLimitWriter;
use App\Services\Dealers\DealerLedgerService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function creditEmployee(UserRole $role, string $mobile, ?int $managerId = null): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@credit.example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad(substr($mobile, -12), 12, '5', STR_PAD_LEFT),
        'pan_number' => 'CRDIT'.substr($mobile, -4).'A',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '6', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => $role->value,
        'reporting_manager_id' => $managerId,
    ])->employee->refresh();
}

function creditDealer(Employee $employee, array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'XYZ Traders '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '97'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'credit_limit' => 0,
        'opening_balance' => 0,
    ], $overrides));
}

function creditAdmin(): User
{
    return User::factory()->create([
        'name' => 'Credit Admin',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function creditProduct(float $price = 50000): Product
{
    return Product::query()->create([
        'product_name' => 'Credit Product '.uniqid(),
        'category' => 'General',
        'uom' => 'Nos',
        'nos_per_case' => 1,
        'gst_percentage' => 0,
        'dealer_price' => $price,
        'status' => true,
    ]);
}

function creditOrderPayload(Dealer $dealer, Product $product, int $cases = 1): array
{
    return [
        'dealer_id' => $dealer->id,
        'remarks' => 'Credit check order',
        'items' => [[
            'product_id' => $product->id,
            'case_quantity' => $cases,
            'rate_per_no' => (float) $product->dealer_price,
            'rate_type' => 'price_list',
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'gst_percentage' => 0,
        ]],
    ];
}

function creditBilled(Dealer $dealer, Employee $employee, float $amount): Order
{
    return Order::query()->create([
        'order_no' => 'BILL'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => '2026-09-01',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_BILLED,
        'payment_type' => 'Credit',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => $amount,
        'bill_number' => 'BILL-'.$amount,
        'bill_date' => '2026-09-01',
        'billed_at' => '2026-09-01 11:00:00',
    ]);
}

function creditPending(Dealer $dealer, Employee $employee, float $amount, string $status = Order::STATUS_APPROVED): Order
{
    return Order::query()->create([
        'order_no' => 'PEND'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => '2026-09-15',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => $status,
        'payment_type' => 'Credit',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => $amount,
    ]);
}

it('allows an order when projected exposure stays under the limit', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100001');
    $employee = creditEmployee(UserRole::Employee, '9812100002', $manager->id);
    $dealer = creditDealer($employee, ['firm_name' => 'XYZ Traders']);
    creditBilled($dealer, $employee, 400000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $product = creditProduct();

    $check = app(DealerCreditExposureService::class)->assess($dealer->fresh(), 50000);

    expect($check->blocksOrder())->toBeFalse()
        ->and($check->status)->toBe('near_limit')
        ->and($check->projectedExposure)->toBe(450000.0);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertOk()
        ->assertJsonPath('grand_total', 50000);
});

it('blocks an order when projected exposure reaches the limit', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100011');
    $employee = creditEmployee(UserRole::Employee, '9812100012', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 480000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $product = creditProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Credit Limit Exceeded');

    expect(Order::query()->where('dealer_id', $dealer->id)->where('status', Order::STATUS_PENDING_APPROVAL)->count())->toBe(0);
});

it('adds an active temporary extension to the base limit', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100021');
    $employee = creditEmployee(UserRole::Employee, '9812100022', $manager->id);
    $dealer = creditDealer($employee, ['firm_name' => 'XYZ Traders']);
    $writer = app(DealerCreditLimitWriter::class);
    $writer->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $writer->extend($dealer, $manager->user, 100000, '2026-10-15', 'Festival dispatch');

    $assessment = app(DealerCreditExposureService::class)->assess($dealer->fresh(), 0);

    expect($assessment->baseLimit)->toBe(500000.0)
        ->and($assessment->extensionAmount)->toBe(100000.0)
        ->and($assessment->effectiveLimit)->toBe(600000.0);

    $note = AppNotification::query()->where('user_id', $employee->user->id)->where('type', 'credit_limit_extended')->first();
    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Credit limit for XYZ Traders has been extended by ₹1,00,000 until 15 Oct 2026.');
});

it('allows the previously blocked order after a temporary extension', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100031');
    $employee = creditEmployee(UserRole::Employee, '9812100032', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 480000);
    $writer = app(DealerCreditLimitWriter::class);
    $writer->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $writer->extend($dealer, $manager->user, 100000, '2026-10-15', 'Festival dispatch');
    $product = creditProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertOk()
        ->assertJsonPath('grand_total', 50000);
});

it('ignores an expired temporary extension', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100041');
    $employee = creditEmployee(UserRole::Employee, '9812100042', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 480000);
    $writer = app(DealerCreditLimitWriter::class);
    $writer->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $writer->extend($dealer, $manager->user, 100000, now()->addDay()->toDateString(), 'Short extension');

    Carbon::setTestNow(now()->addDays(3));

    try {
        $assessment = app(DealerCreditExposureService::class)->assess($dealer->fresh(), 50000);

        expect($assessment->effectiveLimit)->toBe(500000.0)
            ->and($assessment->extensionAmount)->toBe(0.0)
            ->and($assessment->blocksOrder())->toBeTrue()
            ->and(DealerCreditLimitAudit::query()->where('dealer_id', $dealer->id)->where('action', 'extend')->count())->toBe(1)
            ->and(DealerCreditLimitAudit::query()->where('dealer_id', $dealer->id)->where('action', 'expire')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('does not block orders when no base limit is set', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100051');
    $employee = creditEmployee(UserRole::Employee, '9812100052', $manager->id);
    $dealer = creditDealer($employee, ['credit_limit' => 1000]);
    creditBilled($dealer, $employee, 480000);
    $product = creditProduct();

    $assessment = app(DealerCreditExposureService::class)->assess($dealer->fresh(), 50000);
    expect($assessment->limitSet)->toBeFalse()
        ->and($assessment->status)->toBe('no_limit_set')
        ->and($assessment->blocksOrder())->toBeFalse()
        ->and($assessment->message())->toBe('Credit Limit: Not Set');

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertOk();
});

it('forbids an employee from modifying a credit limit', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100061');
    $employee = creditEmployee(UserRole::Employee, '9812100062', $manager->id);
    $dealer = creditDealer($employee);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/manager/dealer-credit-limits/'.$dealer->id, [
            'amount' => 500000,
            'remark' => 'Employee attempt',
        ])
        ->assertForbidden();

    expect(DealerCreditLimitAudit::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('forbids a manager from modifying another managers dealer', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100071');
    $employee = creditEmployee(UserRole::Employee, '9812100072', $manager->id);
    $dealer = creditDealer($employee);
    $otherManager = creditEmployee(UserRole::Manager, '9812100073');

    $this->actingAs($otherManager->user, 'sanctum')
        ->postJson('/api/manager/dealer-credit-limits/'.$dealer->id, [
            'amount' => 500000,
            'remark' => 'Wrong team',
        ])
        ->assertForbidden();

    expect(app(DealerCreditLimitWriter::class)->canManage($otherManager->user, $dealer))->toBeFalse()
        ->and(DealerCreditLimitAudit::query()->count())->toBe(0);
});

it('lets an admin manage every dealer', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100081');
    $employee = creditEmployee(UserRole::Employee, '9812100082', $manager->id);
    $dealer = creditDealer($employee);
    $admin = creditAdmin();

    expect(app(DealerCreditLimitWriter::class)->canManage($admin, $dealer))->toBeTrue();

    $this->actingAs($admin);
    expect(DealerCreditLimitResource::canViewAny())->toBeTrue();

    app(DealerCreditLimitWriter::class)->setBase($dealer, $admin, 500000, 'Admin opening limit');

    expect((float) $dealer->creditLimit()->first()->base_limit)->toBe(500000.0)
        ->and(DealerCreditLimitResource::getEloquentQuery()->whereKey($dealer->id)->exists())->toBeTrue();

    $this->actingAs($employee->user);
    expect(DealerCreditLimitResource::canViewAny())->toBeFalse();
});

it('counts pending unbilled orders as exposure so they cannot bypass the limit', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100091');
    $employee = creditEmployee(UserRole::Employee, '9812100092', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 400000);
    creditPending($dealer, $employee, 80000, Order::STATUS_APPROVED);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $product = creditProduct();

    $exposure = app(DealerCreditExposureService::class);
    $sqlPending = (float) DB::query()
        ->selectRaw(DealerCreditExposureService::pendingExposureSql().' as pending_exposure')
        ->from('dealers')
        ->where('id', $dealer->id)
        ->value('pending_exposure');

    expect($exposure->pendingExposure($dealer->fresh()))->toBe(80000.0)
        ->and($sqlPending)->toBe(80000.0)
        ->and($exposure->assess($dealer->fresh(), 50000)->blocksOrder())->toBeTrue();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertStatus(422);
});

it('does not double count orders already posted to the ledger', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100101');
    $employee = creditEmployee(UserRole::Employee, '9812100102', $manager->id);
    $dealer = creditDealer($employee);
    $billed = creditBilled($dealer, $employee, 400000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $product = creditProduct();

    $exposure = app(DealerCreditExposureService::class);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $billed->id)->count())->toBe(1)
        ->and($exposure->pendingExposure($dealer->fresh()))->toBe(0.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(400000.0)
        ->and($exposure->assess($dealer->fresh(), 50000)->blocksOrder())->toBeFalse();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertOk();
});

it('stops a second order from bypassing the remaining limit while another submission holds the lock', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100111');
    $employee = creditEmployee(UserRole::Employee, '9812100112', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 440000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');
    $product = creditProduct();

    config(['paramgold.credit_limit_lock_seconds' => 0]);
    DB::table('dealer_credit_order_locks')->insert([
        'dealer_id' => $dealer->id,
        'owner' => 'held-by-other-order',
        'locked_until' => now()->addMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertStatus(423);

    expect(Order::query()->where('dealer_id', $dealer->id)->where('status', Order::STATUS_PENDING_APPROVAL)->count())->toBe(0);

    DB::table('dealer_credit_order_locks')->where('dealer_id', $dealer->id)->delete();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertOk();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', creditOrderPayload($dealer, $product))
        ->assertStatus(422);

    expect(Order::query()->where('dealer_id', $dealer->id)->where('status', Order::STATUS_PENDING_APPROVAL)->count())->toBe(1);
});

it('returns the same figures from the preview api as the order submit service', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100121');
    $employee = creditEmployee(UserRole::Employee, '9812100122', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 400000);
    creditPending($dealer, $employee, 20000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');

    $expected = app(DealerCreditExposureService::class)->assess($dealer->fresh(), 50000)->toArray();

    $data = $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/dealers/'.$dealer->id.'/credit-check?order_amount=50000')
        ->assertOk()
        ->json('data');

    expect($data['current_outstanding'])->toEqual($expected['current_outstanding'])
        ->and($data['pending_exposure'])->toEqual($expected['pending_exposure'])
        ->and($data['projected_exposure'])->toEqual($expected['projected_exposure'])
        ->and($data['effective_limit'])->toEqual($expected['effective_limit'])
        ->and($data['available_limit'])->toEqual($expected['available_limit'])
        ->and($data['status'])->toBe($expected['status'])
        ->and($data['blocks_order'])->toBe($expected['blocks_order']);
});

it('does not change ledger outstanding when a credit limit is set', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100131');
    $employee = creditEmployee(UserRole::Employee, '9812100132', $manager->id);
    $dealer = creditDealer($employee, ['credit_limit' => 0]);
    $billed = creditBilled($dealer, $employee, 400000);
    $before = app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh());
    $debit = (float) DealerTallyEntry::query()->where('source_id', $billed->id)->value('debit');

    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');

    $summary = app(DealerLedgerService::class)->getAccountSummary($dealer->fresh());

    expect(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe($before)
        ->and($summary['current_outstanding'])->toBe($before)
        ->and($debit)->toBe(400000.0)
        ->and((float) DealerTallyEntry::query()->where('source_id', $billed->id)->value('debit'))->toBe($debit)
        ->and((float) $dealer->fresh()->credit_limit)->toBe(0.0)
        ->and($summary['credit_limit']['base_limit'])->toBe(500000.0);
});

it('keeps every set edit extend and expire row in history', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100141');
    $employee = creditEmployee(UserRole::Employee, '9812100142', $manager->id);
    $dealer = creditDealer($employee);
    $writer = app(DealerCreditLimitWriter::class);

    $writer->setBase($dealer, $manager->user, 500000, 'Initial limit');
    $writer->setBase($dealer, $manager->user, 550000, 'Revised limit');
    $writer->extend($dealer, $manager->user, 100000, '2026-10-15', 'Festival dispatch');
    $writer->expireExtension($dealer, $manager->user, 'Season ended');

    $audits = DealerCreditLimitAudit::query()->where('dealer_id', $dealer->id)->orderBy('id')->get();

    expect($audits)->toHaveCount(4)
        ->and($audits[0]->action)->toBe('set')
        ->and($audits[0]->previous_base)->toBeNull()
        ->and((float) $audits[0]->new_base)->toBe(500000.0)
        ->and($audits[0]->remark)->toBe('Initial limit')
        ->and((int) $audits[0]->changed_by_user_id)->toBe($manager->user->id)
        ->and($audits[0]->changed_by_role)->toBe('manager')
        ->and($audits[1]->action)->toBe('edit')
        ->and((float) $audits[1]->previous_base)->toBe(500000.0)
        ->and((float) $audits[1]->new_base)->toBe(550000.0)
        ->and($audits[1]->remark)->toBe('Revised limit')
        ->and($audits[2]->action)->toBe('extend')
        ->and((float) $audits[2]->extension_amount)->toBe(100000.0)
        ->and((float) $audits[2]->effective_limit)->toBe(650000.0)
        ->and($audits[2]->valid_until->toDateString())->toBe('2026-10-15')
        ->and($audits[2]->remark)->toBe('Festival dispatch')
        ->and($audits[3]->action)->toBe('expire')
        ->and((float) $audits[3]->extension_amount)->toBe(100000.0)
        ->and((float) $audits[3]->effective_limit)->toBe(550000.0)
        ->and($audits[3]->valid_until->toDateString())->toBe('2026-10-15')
        ->and($audits[3]->remark)->toBe('Season ended');

    expect(DealerCreditLimitAudit::query()->where('dealer_id', $dealer->id)->count())->toBe(4);
});

it('does not send a duplicate near-limit warning for the same status', function (): void {
    $manager = creditEmployee(UserRole::Manager, '9812100151');
    $employee = creditEmployee(UserRole::Employee, '9812100152', $manager->id);
    $dealer = creditDealer($employee);
    creditBilled($dealer, $employee, 400000);
    app(DealerCreditLimitWriter::class)->setBase($dealer, $manager->user, 500000, 'Opening limit');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/dealers/'.$dealer->id.'/credit-check?order_amount=1000')
        ->assertOk()
        ->assertJsonPath('data.status', 'near_limit');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/dealers/'.$dealer->id.'/credit-check?order_amount=1000')
        ->assertOk();

    expect(AppNotification::query()->where('user_id', $employee->user->id)->where('type', 'credit_limit_warning')->count())->toBe(1);
});
