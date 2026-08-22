<?php

use App\Enums\UserRole;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Widgets\AdminDirectorAttentionWidget;
use App\Filament\Widgets\AdminDirectorPaymentOverviewWidget;
use App\Filament\Widgets\AdminDirectorWelcomeWidget;
use App\Models\Attendance;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Support\AttendanceCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 10:30:00', AttendanceCalendar::TIMEZONE));
    Cache::flush();
    app()->forgetInstance(DirectorDashboardDataService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function directorDashDirector(string $name = 'Dashboard Director'): User
{
    return User::query()->create([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function directorDashAdmin(): User
{
    return User::query()->create([
        'name' => 'Dashboard Admin',
        'email' => 'dash.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function directorDashEmployee(string $name, string $mobile): Employee
{
    static $n = 400;
    $n++;

    $employee = Employee::query()->create([
        'full_name' => $name,
        'mobile' => $mobile,
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (400000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'DDDDD'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (400000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);

    User::query()->create([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'employee_id' => $employee->id,
    ]);

    return $employee;
}

function directorDashDealer(array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'Dash Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'credit_limit' => 0,
        'outstanding' => 0,
    ], $overrides));
}

function directorDashOrder(int $employeeId, int $dealerId, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => AttendanceCalendar::today()->toDateString(),
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 100000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100000,
    ], $overrides));
}

it('counts today sales, punch-in of active employees, and pending workflow orders', function (): void {
    $punched = directorDashEmployee('Punched Sales', '9910000001');
    $notPunched = directorDashEmployee('Not Punched Sales', '9910000002');
    $inactive = directorDashEmployee('Inactive Sales', '9910000003');
    $inactive->update(['status' => false]);
    $dealer = directorDashDealer();
    $today = AttendanceCalendar::today()->toDateString();

    Attendance::query()->create([
        'employee_id' => $punched->id,
        'attendance_date' => $today,
        'punch_in_time' => '09:00:00',
        'approval_status' => 'Pending',
    ]);

    directorDashOrder($punched->id, $dealer->id, ['grand_total' => 845000, 'status' => Order::STATUS_APPROVED]);
    directorDashOrder($punched->id, $dealer->id, [
        'order_date' => AttendanceCalendar::today()->copy()->subDay()->toDateString(),
        'grand_total' => 500000,
        'status' => Order::STATUS_DISPATCHED,
    ]);
    $pending = directorDashOrder($punched->id, $dealer->id, [
        'order_date' => AttendanceCalendar::today()->copy()->subDay()->toDateString(),
        'status' => Order::STATUS_PENDING_APPROVAL,
    ]);
    $billing = directorDashOrder($punched->id, $dealer->id, [
        'order_date' => AttendanceCalendar::today()->copy()->subDay()->toDateString(),
        'status' => Order::STATUS_PENDING_FOR_BILLING,
    ]);
    $billed = directorDashOrder($punched->id, $dealer->id, [
        'order_date' => AttendanceCalendar::today()->copy()->subDay()->toDateString(),
        'status' => Order::STATUS_BILLED,
    ]);
    directorDashOrder($punched->id, $dealer->id, ['status' => Order::STATUS_REJECTED, 'grand_total' => 999999]);

    Collection::query()->create([
        'receipt_no' => 'RCP-DASH-1',
        'collection_date' => $today,
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $punched->id,
        'amount' => 320000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-DASH-1',
    ]);

    $snapshot = app(DirectorDashboardDataService::class)->snapshot(directorDashDirector());

    expect($snapshot['today_sales'])->toBe(845000.0)
        ->and($snapshot['today_collection'])->toBe(320000.0)
        ->and($snapshot['active_employees'])->toBe(2)
        ->and($snapshot['punched_in'])->toBe(1)
        ->and($snapshot['not_punched_in'])->toBe(1)
        ->and($snapshot['pending_orders'])->toBe(4)
        ->and($snapshot['pipeline']['placed'])->toBe(1)
        ->and($snapshot['pipeline']['sent_for_bill'])->toBe(1)
        ->and($snapshot['pipeline']['billed'])->toBe(1)
        ->and($notPunched->id)->toBeInt();

    expect(DirectorDashboardDataService::formatCompact(845000))->toBe('₹8.45 L')
        ->and(DirectorDashboardDataService::formatCompact(20000000))->toBe('₹2.00 Cr');

    $pending->forceFill(['created_at' => now('Asia/Kolkata')->subHours(25)])->saveQuietly();
    $billing->forceFill(['sent_for_bill_at' => now('Asia/Kolkata')->subHours(13)])->saveQuietly();
    $billed->forceFill(['billed_at' => now('Asia/Kolkata')->subHours(25)])->saveQuietly();
    app()->forgetInstance(DirectorDashboardDataService::class);

    $delayed = app(DirectorDashboardDataService::class)->snapshot();
    expect($delayed['delays']['pending_24h'])->toBe(1)
        ->and($delayed['delays']['billing_12h'])->toBe(1)
        ->and($delayed['delays']['dispatch_24h'])->toBe(1);
});

it('counts payment approvals for the logged-in director', function (): void {
    $krishna = directorDashDirector('Krishna Rajbinde');
    $bhagwan = directorDashDirector('Bhagwan Kakde');
    $admin = directorDashAdmin();

    PaymentRequest::query()->create([
        'request_no' => 'PR-DASH-1',
        'vendor_name' => 'Vendor A',
        'vendor_mobile' => '9876543210',
        'amount' => 500000,
        'status' => PaymentRequest::STATUS_PENDING_FIRST,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ]);
    PaymentRequest::query()->create([
        'request_no' => 'PR-DASH-2',
        'vendor_name' => 'Vendor B',
        'vendor_mobile' => '9876543211',
        'amount' => 280000,
        'status' => PaymentRequest::STATUS_PENDING_SECOND,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ]);
    PaymentRequest::query()->create([
        'request_no' => 'PR-DASH-3',
        'vendor_name' => 'Vendor C',
        'vendor_mobile' => '9876543212',
        'amount' => 100000,
        'status' => PaymentRequest::STATUS_PAYMENT_DONE,
        'created_by' => $admin->id,
        'payment_done_at' => now('Asia/Kolkata'),
        'reminder_count' => 0,
    ]);

    $forKrishna = app(DirectorDashboardDataService::class)->snapshot($krishna);
    expect($forKrishna['payments']['my_pending_count'])->toBe(1)
        ->and($forKrishna['payments']['my_filter'])->toBe('pending_krishna')
        ->and($forKrishna['payments']['next_count'])->toBe(1)
        ->and($forKrishna['payments']['paid_today_count'])->toBe(1);

    app()->forgetInstance(DirectorDashboardDataService::class);
    $forBhagwan = app(DirectorDashboardDataService::class)->snapshot($bhagwan);
    expect($forBhagwan['payments']['my_pending_count'])->toBe(1)
        ->and($forBhagwan['payments']['my_filter'])->toBe('pending_bhagwan');
});

it('opens matching filtered lists for sales, collections, pending orders, and high outstanding', function (): void {
    $admin = directorDashAdmin();
    $employee = directorDashEmployee('List Sales', '9920000001');
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    $todayDealer = directorDashDealer(['firm_name' => 'Today Dealer']);
    $yesterdayDealer = directorDashDealer(['firm_name' => 'Yesterday Dealer']);
    $highDealer = directorDashDealer([
        'firm_name' => 'High Outstanding Dealer',
        'credit_limit' => 100000,
        'opening_balance' => 95000,
        'opening_balance_date' => '2026-04-01',
    ]);
    $okDealer = directorDashDealer([
        'firm_name' => 'Healthy Dealer',
        'credit_limit' => 100000,
        'opening_balance' => 10000,
        'opening_balance_date' => '2026-04-01',
    ]);

    $todayOrder = directorDashOrder($employee->id, $todayDealer->id, ['grand_total' => 50000]);
    $yesterdayOrder = directorDashOrder($employee->id, $yesterdayDealer->id, [
        'order_date' => $yesterday,
        'status' => Order::STATUS_DISPATCHED,
    ]);
    $pendingBilling = directorDashOrder($employee->id, $todayDealer->id, [
        'order_date' => $yesterday,
        'status' => Order::STATUS_PENDING_FOR_BILLING,
    ]);

    $todayCollection = Collection::query()->create([
        'receipt_no' => 'RCP-DASH-T',
        'collection_date' => $today,
        'dealer_id' => $todayDealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 12000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-DASH-T',
    ]);
    $yesterdayCollection = Collection::query()->create([
        'receipt_no' => 'RCP-DASH-Y',
        'collection_date' => $yesterday,
        'dealer_id' => $yesterdayDealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 8000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-DASH-Y',
    ]);

    Livewire::actingAs($admin)
        ->test(ListOrders::class)
        ->filterTable('order_date', ['date' => $today])
        ->assertCanSeeTableRecords([$todayOrder])
        ->assertCanNotSeeTableRecords([$yesterdayOrder, $pendingBilling]);

    Livewire::actingAs($admin)
        ->test(ListOrders::class)
        ->filterTable('action_required')
        ->assertCanSeeTableRecords([$todayOrder, $pendingBilling])
        ->assertCanNotSeeTableRecords([$yesterdayOrder]);

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->filterTable('collection_date', ['date' => $today])
        ->assertCanSeeTableRecords([$todayCollection])
        ->assertCanNotSeeTableRecords([$yesterdayCollection])
        ->assertCountTableRecords(1);

    Livewire::actingAs($admin)
        ->test(ListDealers::class)
        ->filterTable('high_outstanding')
        ->assertCanSeeTableRecords([$highDealer])
        ->assertCanNotSeeTableRecords([$okDealer, $todayDealer, $yesterdayDealer]);
});

it('renders director monitoring widgets and hides them from managers', function (): void {
    $director = directorDashDirector();
    $manager = User::query()->create([
        'name' => 'Dashboard Manager',
        'email' => 'dash.manager.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Manager->value,
    ]);

    Livewire::actingAs($director)
        ->test(AdminDirectorWelcomeWidget::class)
        ->assertSuccessful()
        ->assertSee('Director Dashboard')
        ->assertSee('Today Sales');

    Livewire::actingAs($director)
        ->test(AdminDirectorAttentionWidget::class)
        ->assertSuccessful()
        ->assertSee('Attention Required');

    Livewire::actingAs($director)
        ->test(AdminDirectorPaymentOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Pending My Approval');

    $this->actingAs($manager);
    expect(AdminDirectorWelcomeWidget::canView())->toBeFalse()
        ->and(AdminDirectorAttentionWidget::canView())->toBeFalse();
});

it('counts all active non-dispatched order statuses as pending and matches the orders filter', function (): void {
    $director = directorDashDirector();
    $employee = directorDashEmployee('Pending Scope Sales', '9910000099');
    $dealer = directorDashDealer();

    $included = [
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_PENDING_APPROVAL]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_APPROVED]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_ON_HOLD]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_REVERTED_TO_MANAGER]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_PENDING_FOR_BILLING]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_BILLED]),
    ];
    $dispatched = directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_DISPATCHED]);
    $rejected = directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_REJECTED]);

    $snapshot = app(DirectorDashboardDataService::class)->snapshot($director);

    expect($snapshot['pending_orders'])->toBe(6)
        ->and(Order::query()->activeNonDispatched()->count())->toBe(6);

    Livewire::actingAs($director)
        ->test(ListOrders::class)
        ->filterTable('pending_not_dispatched')
        ->assertCanSeeTableRecords($included)
        ->assertCanNotSeeTableRecords([$dispatched, $rejected])
        ->assertCountTableRecords(6);

    Livewire::actingAs($director)
        ->test(AdminDirectorWelcomeWidget::class)
        ->assertSuccessful()
        ->assertSee('Today Field Visits')
        ->assertSeeHtml('filters%5Bpending_not_dispatched%5D%5BisActive%5D=1')
        ->assertDontSee('Payment Approval');
});

it('exposes a monitoring snapshot on the director mobile dashboard api', function () {
    $director = directorDashDirector('Mobile Dashboard Director');
    $this->actingAs($director, 'sanctum');

    $this->getJson('/api/director/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'company_summary' => [
                'targets',
                'orders',
                'operations',
                'payment_requests',
            ],
            'employee_performance',
            'monitoring' => [
                'today_sales',
                'today_collection',
                'punched_in',
                'not_punched_in',
                'no_field_activity_today',
                'pipeline' => [
                    'placed',
                    'approved',
                    'sent_for_bill',
                    'billed',
                    'dispatched',
                    'on_hold',
                    'reverted_to_manager',
                ],
                'payments',
                'team_performance',
            ],
        ]);
});

it('lists director pending orders as all active non-dispatched statuses', function (): void {
    $director = directorDashDirector('Pending Orders Director');
    $employee = directorDashEmployee('Pending List Sales', '9910000088');
    $dealer = directorDashDealer();

    $included = [
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_PENDING_APPROVAL]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_APPROVED]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_ON_HOLD]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_REVERTED_TO_MANAGER]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_PENDING_FOR_BILLING]),
        directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_BILLED]),
    ];
    $dispatched = directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_DISPATCHED]);
    $rejected = directorDashOrder($employee->id, $dealer->id, ['status' => Order::STATUS_REJECTED]);

    $this->actingAs($director, 'sanctum');

    $response = $this->getJson('/api/director/orders?status=pending&per_page=100')
        ->assertOk()
        ->assertJsonPath('counts.pending', 6)
        ->assertJsonPath('meta.total', 6);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing(collect($included)->pluck('id')->all())
        ->and($ids)->not->toContain($dispatched->id)
        ->and($ids)->not->toContain($rejected->id);

    $statuses = collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all();
    expect($statuses)->toEqualCanonicalizing(Order::activeNonDispatchedStatuses());

    $included[5]->update(['status' => Order::STATUS_DISPATCHED]);

    $afterDispatch = $this->getJson('/api/director/orders?status=pending&per_page=100')
        ->assertOk();

    $afterIds = collect($afterDispatch->json('data'))->pluck('id')->all();
    expect($afterDispatch->json('meta.total'))->toBe(5)
        ->and($afterIds)->not->toContain($included[5]->id);
});

it('lists director today sales orders by order date', function (): void {
    $director = directorDashDirector('Today Sales Director');
    $employee = directorDashEmployee('Today Sales Exec', '9910000077');
    $dealer = directorDashDealer();
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    $todayOrder = directorDashOrder($employee->id, $dealer->id, [
        'order_date' => $today,
        'status' => Order::STATUS_PENDING_APPROVAL,
    ]);
    $yesterdayOrder = directorDashOrder($employee->id, $dealer->id, [
        'order_date' => $yesterday,
        'status' => Order::STATUS_APPROVED,
    ]);

    $this->actingAs($director, 'sanctum');

    $response = $this->getJson("/api/director/orders?date_from={$today}&date_to={$today}&per_page=100")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($todayOrder->id)
        ->and($ids)->not->toContain($yesterdayOrder->id);
});

it('lists director dealer visits for today', function (): void {
    $director = directorDashDirector('Visit Director');
    $employee = directorDashEmployee('Visit Sales', '9910000066');
    $dealer = directorDashDealer(['firm_name' => 'Visited Agro', 'village' => 'Tirthpuri']);
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    $todayVisit = DealerVisit::query()->create([
        'employee_id' => $employee->id,
        'dealer_id' => $dealer->id,
        'visit_date' => $today,
        'visit_time' => '10:15:00',
        'photo_path' => 'dealer-visits/today.jpg',
        'latitude' => 19.8765432,
        'longitude' => 75.3432109,
        'accuracy' => 8.5,
        'location_captured_at' => $today.' 10:15:00',
        'status' => DealerVisit::STATUS_COMPLETED,
    ]);
    DealerVisit::query()->create([
        'employee_id' => $employee->id,
        'dealer_id' => $dealer->id,
        'visit_date' => $yesterday,
        'visit_time' => '11:00:00',
        'photo_path' => 'dealer-visits/yesterday.jpg',
        'latitude' => 19.8765432,
        'longitude' => 75.3432109,
        'accuracy' => 8.5,
        'location_captured_at' => $yesterday.' 11:00:00',
        'status' => DealerVisit::STATUS_COMPLETED,
    ]);

    $this->actingAs($director, 'sanctum');

    $list = $this->getJson('/api/director/dealer-visits')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    expect($list->json('data.0.id'))->toBe($todayVisit->id)
        ->and($list->json('data.0.dealer_name'))->toBe('Visited Agro')
        ->and($list->json('data.0.village'))->toBe('Tirthpuri')
        ->and($list->json('data.0.employee_name'))->toBe('Visit Sales');

    $this->getJson('/api/director/dealer-visits/'.$todayVisit->id)
        ->assertOk()
        ->assertJsonPath('data.id', $todayVisit->id)
        ->assertJsonPath('data.dealer_name', 'Visited Agro')
        ->assertJsonPath('data.maps_url', $todayVisit->mapsUrl());
});
