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
use App\Models\Crop;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\Employee;
use App\Models\FieldActivity;
use App\Models\FieldActivityRecommendation;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Support\AttendanceCalendar;
use App\Support\PublicMediaUrl;
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

it('groups director today collections dealer-wise using dashboard amount rules', function (): void {
    $director = directorDashDirector('Today Collection Director');
    $akash = directorDashEmployee('Akash Mundhe', '9910000055');
    $other = directorDashEmployee('Other Collector', '9910000056');
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    $chaitanya = directorDashDealer([
        'firm_name' => 'Chaitanya Agro Traders',
        'village' => 'Tirthpuri',
        'assigned_employee_id' => $akash->id,
    ]);
    $secondDealer = directorDashDealer([
        'firm_name' => 'Second Agro',
        'village' => 'Jalna',
        'assigned_employee_id' => $other->id,
    ]);

    $first = Collection::query()->create([
        'receipt_no' => 'RCP-TODAY-1',
        'collection_date' => $today,
        'dealer_id' => $chaitanya->id,
        'sales_employee_id' => $akash->id,
        'amount' => 15000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-TODAY-1',
        'remarks' => 'Morning collection',
        'photo_path' => 'collections/today-1.jpg',
    ]);
    $second = Collection::query()->create([
        'receipt_no' => 'RCP-TODAY-2',
        'collection_date' => $today,
        'dealer_id' => $chaitanya->id,
        'sales_employee_id' => $akash->id,
        'amount' => 10000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'UPI',
        'transaction_number' => 'TXN-TODAY-2',
        'remarks' => 'Afternoon collection',
    ]);
    Collection::query()->create([
        'receipt_no' => 'RCP-TODAY-3',
        'collection_date' => $today,
        'dealer_id' => $secondDealer->id,
        'sales_employee_id' => $other->id,
        'amount' => 25000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-TODAY-3',
    ]);
    Collection::query()->create([
        'receipt_no' => 'RCP-YDAY-1',
        'collection_date' => $yesterday,
        'dealer_id' => $chaitanya->id,
        'sales_employee_id' => $akash->id,
        'amount' => 99999,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-YDAY-1',
    ]);

    $first->forceFill(['created_at' => now('Asia/Kolkata')->setTime(12, 15)])->saveQuietly();
    $second->forceFill(['created_at' => now('Asia/Kolkata')->setTime(15, 30)])->saveQuietly();

    $this->actingAs($director, 'sanctum');

    $dashboardTotal = (float) app(DirectorDashboardDataService::class)->snapshot($director)['today_collection'];

    $list = $this->getJson('/api/director/collections/today/dealers')
        ->assertOk()
        ->assertJsonPath('date', $today)
        ->assertJsonPath('total_collection', 50000);

    expect((float) $list->json('total_collection'))->toBe($dashboardTotal)
        ->and($list->json('dealers'))->toHaveCount(2);

    $chaitanyaRow = collect($list->json('dealers'))->firstWhere('dealer_id', $chaitanya->id);
    expect($chaitanyaRow['dealer_name'])->toBe('Chaitanya Agro Traders')
        ->and($chaitanyaRow['dealer_code'])->toBe($chaitanya->fresh()->dealer_code)
        ->and($chaitanyaRow['village'])->toBe('Tirthpuri')
        ->and($chaitanyaRow['employee_name'])->toBe('Akash Mundhe')
        ->and((float) $chaitanyaRow['total_amount'])->toBe(25000.0)
        ->and($chaitanyaRow['entries_count'])->toBe(2);

    $details = $this->getJson('/api/director/collections?dealer_id='.$chaitanya->id)
        ->assertOk()
        ->assertJsonPath('date', $today)
        ->assertJsonPath('dealer.dealer_name', 'Chaitanya Agro Traders')
        ->assertJsonPath('total_amount', 25000)
        ->assertJsonPath('entries_count', 2);

    $ids = collect($details->json('data'))->pluck('id')->all();
    expect($ids)->toEqual([$second->id, $first->id])
        ->and((float) $details->json('data.0.amount'))->toBe(10000.0)
        ->and($details->json('data.0.status'))->toBe(Collection::STATUS_PENDING)
        ->and($details->json('data.0.employee_name'))->toBe('Akash Mundhe')
        ->and((float) $details->json('data.1.amount'))->toBe(15000.0)
        ->and($details->json('data.1.status'))->toBe(Collection::STATUS_RECEIVED)
        ->and($details->json('data.1.photo_url'))->toContain('/storage/collections/today-1.jpg')
        ->and($details->json('data.1.supporting_image_url'))->toBe($details->json('data.1.photo_url'))
        ->and($details->json('data.1.photo_url'))->toStartWith('http')
        ->and($details->json('data.1.photo_url'))->not->toContain('storage/app/public');

    $this->getJson('/api/director/collections/'.$first->id)
        ->assertOk()
        ->assertJsonPath('data.id', $first->id)
        ->assertJsonPath('data.amount', 15000)
        ->assertJsonPath('data.status', Collection::STATUS_RECEIVED)
        ->assertJsonPath('data.supporting_image_url', $first->photoUrl());
});

it('filters director collections by week, month, employee, and date range', function (): void {
    $director = directorDashDirector('Collection Filter Director');
    $akash = directorDashEmployee('Akash Mundhe', '9910000033');
    $ganesh = directorDashEmployee('Ganesh Dere', '9910000034');
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();
    $lastMonth = AttendanceCalendar::today()->copy()->startOfMonth()->subDay()->toDateString();

    $dealer = directorDashDealer([
        'firm_name' => 'Filter Agro',
        'village' => 'Tirthpuri',
        'assigned_employee_id' => $akash->id,
    ]);
    $otherDealer = directorDashDealer([
        'firm_name' => 'Other Agro',
        'village' => 'Partur',
        'assigned_employee_id' => $ganesh->id,
    ]);

    $todayAkash = Collection::query()->create([
        'receipt_no' => 'RCP-FIL-1',
        'collection_date' => $today,
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $akash->id,
        'amount' => 10000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-FIL-1',
        'photo_path' => 'collections/filter-today.jpg',
    ]);
    $yesterdayAkash = Collection::query()->create([
        'receipt_no' => 'RCP-FIL-2',
        'collection_date' => $yesterday,
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $akash->id,
        'amount' => 4000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'UPI',
        'transaction_number' => 'TXN-FIL-2',
    ]);
    $todayGanesh = Collection::query()->create([
        'receipt_no' => 'RCP-FIL-3',
        'collection_date' => $today,
        'dealer_id' => $otherDealer->id,
        'sales_employee_id' => $ganesh->id,
        'amount' => 7000,
        'status' => Collection::STATUS_PENDING,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-FIL-3',
    ]);
    $oldAkash = Collection::query()->create([
        'receipt_no' => 'RCP-FIL-4',
        'collection_date' => $lastMonth,
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $akash->id,
        'amount' => 50000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-FIL-4',
    ]);

    $this->actingAs($director, 'sanctum');

    $todayList = $this->getJson('/api/director/collections/today/dealers')
        ->assertOk()
        ->assertJsonPath('period', 'today')
        ->assertJsonPath('date', $today)
        ->assertJsonPath('total_collection', 17000);

    expect($todayList->json('dealers'))->toHaveCount(2)
        ->and($todayList->json('entries_count'))->toBe(2)
        ->and($todayList->json('employees'))->toHaveCount(2);

    $weekList = $this->getJson('/api/director/collections/today/dealers?period=week')
        ->assertOk()
        ->assertJsonPath('period', 'week');

    expect((float) $weekList->json('total_collection'))->toBe(21000.0)
        ->and($weekList->json('entries_count'))->toBe(3);

    $monthList = $this->getJson('/api/director/collections/today/dealers?period=month')
        ->assertOk()
        ->assertJsonPath('period', 'month');

    expect((float) $monthList->json('total_collection'))->toBe(21000.0)
        ->and($monthList->json('date_from'))->toBe(AttendanceCalendar::today()->copy()->startOfMonth()->toDateString())
        ->and($monthList->json('date_to'))->toBe($today);

    $employeeList = $this->getJson('/api/director/collections/today/dealers?period=today&employee_id='.$ganesh->id)
        ->assertOk()
        ->assertJsonPath('employee_id', $ganesh->id)
        ->assertJsonPath('total_collection', 7000);

    expect($employeeList->json('dealers'))->toHaveCount(1)
        ->and($employeeList->json('dealers.0.dealer_name'))->toBe('Other Agro')
        ->and($employeeList->json('employees'))->toHaveCount(2);

    $combined = $this->getJson(
        '/api/director/collections?dealer_id='.$dealer->id.'&period=month&employee_id='.$akash->id
    )
        ->assertOk()
        ->assertJsonPath('entries_count', 2)
        ->assertJsonPath('total_amount', 14000);

    $ids = collect($combined->json('data'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$todayAkash->id, $yesterdayAkash->id])
        ->and($ids)->not->toContain($todayGanesh->id)
        ->and($ids)->not->toContain($oldAkash->id);

    $custom = $this->getJson(
        '/api/director/collections/today/dealers?date_from='.$yesterday.'&date_to='.$yesterday
    )
        ->assertOk()
        ->assertJsonPath('total_collection', 4000)
        ->assertJsonPath('dealers_count', 1);
});


it('lists director today field visits grouped by employee matching dashboard count', function (): void {
    $director = directorDashDirector('Field Visit Director');
    $akash = directorDashEmployee('Akash Mundhe', '9910000044');
    $ganesh = directorDashEmployee('Ganesh Dere', '9910000045');
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();
    $cotton = Crop::query()->where('name', 'Cotton')->firstOrFail();
    $soybean = Crop::query()->where('name', 'Soybean')->firstOrFail();
    $product = Product::query()->create([
        'product_name' => 'Param Gold Spray',
        'category' => 'General',
        'uom' => 'Kg',
        'nos_per_case' => 10,
        'gst_percentage' => 18,
        'dealer_price' => 250,
        'status' => true,
    ]);

    $first = FieldActivity::query()->create([
        'employee_id' => $akash->id,
        'farmer_name' => 'Ramesh Patil',
        'farmer_mobile' => '9876543210',
        'village' => 'Tirthpuri',
        'taluka' => 'Partur',
        'district' => 'Jalna',
        'crop_id' => $cotton->id,
        'activity_date' => $today,
        'activity_time' => '10:15:00',
        'remark' => 'Morning visit',
        'photo_path' => 'field-activities/today-1.jpg',
        'latitude' => 19.8765432,
        'longitude' => 75.3432109,
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivityRecommendation::query()->create([
        'field_activity_id' => $first->id,
        'crop_id' => $cotton->id,
        'product_id' => $product->id,
        'dosage' => '1 kg/acre',
    ]);
    FieldActivity::query()->create([
        'employee_id' => $akash->id,
        'farmer_name' => 'Suresh Jadhav',
        'farmer_mobile' => '9765432109',
        'village' => 'Ghansawangi',
        'taluka' => 'Ghansawangi',
        'district' => 'Jalna',
        'crop_id' => $soybean->id,
        'activity_date' => $today,
        'activity_time' => '12:30:00',
        'photo_path' => 'field-activities/today-2.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $akash->id,
        'farmer_name' => 'Partur Farmer',
        'farmer_mobile' => '9654321098',
        'village' => 'Partur',
        'taluka' => 'Partur',
        'district' => 'Jalna',
        'crop_id' => $cotton->id,
        'activity_date' => $today,
        'activity_time' => '15:00:00',
        'photo_path' => 'field-activities/today-3.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $ganesh->id,
        'farmer_name' => 'Other Farmer',
        'farmer_mobile' => '9543210987',
        'village' => 'Ambad',
        'taluka' => 'Ambad',
        'district' => 'Jalna',
        'crop_id' => $cotton->id,
        'activity_date' => $today,
        'activity_time' => '11:00:00',
        'photo_path' => 'field-activities/today-4.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
    FieldActivity::query()->create([
        'employee_id' => $akash->id,
        'farmer_name' => 'Yesterday Farmer',
        'village' => 'Tirthpuri',
        'taluka' => 'Partur',
        'activity_date' => $yesterday,
        'activity_time' => '09:00:00',
        'photo_path' => 'field-activities/yesterday.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($director, 'sanctum');

    $dashboardCount = (int) app(DirectorDashboardDataService::class)->snapshot($director)['field_visits'];

    $list = $this->getJson('/api/director/field-visits/today')
        ->assertOk()
        ->assertJsonPath('date', $today)
        ->assertJsonPath('total_visits', 4)
        ->assertJsonPath('employees_visited', 2);

    expect($list->json('total_visits'))->toBe($dashboardCount)
        ->and($list->json('employees'))->toHaveCount(2);

    $akashGroup = collect($list->json('employees'))->firstWhere('employee_name', 'Akash Mundhe');
    expect($akashGroup['visits_count'])->toBe(3)
        ->and($akashGroup['visits'])->toHaveCount(3)
        ->and(collect($akashGroup['visits'])->pluck('village')->all())
        ->toEqual(['Partur', 'Ghansawangi', 'Tirthpuri'])
        ->and($akashGroup['visits'][2]['farmer_name'])->toBe('Ramesh Patil')
        ->and($akashGroup['visits'][2]['farmer_mobile'])->toBe('9876543210')
        ->and($akashGroup['visits'][2]['crop_name'])->toBe('Cotton')
        ->and($akashGroup['visits'][2]['product_recommendation'])->toBe('Param Gold Spray')
        ->and($akashGroup['visits'][2]['activity_time'])->toBe('10:15');

    $this->getJson('/api/director/field-visits/'.$first->id)
        ->assertOk()
        ->assertJsonPath('data.id', $first->id)
        ->assertJsonPath('data.employee_name', 'Akash Mundhe')
        ->assertJsonPath('data.employee_code', $akash->fresh()->employee_code)
        ->assertJsonPath('data.farmer_name', 'Ramesh Patil')
        ->assertJsonPath('data.village', 'Tirthpuri')
        ->assertJsonPath('data.crop_name', 'Cotton')
        ->assertJsonPath('data.maps_url', $first->mapsUrl())
        ->assertJsonPath('data.photo_url', $first->photoUrl());
});

it('normalizes collection supporting image paths into public http urls', function (): void {
    expect(PublicMediaUrl::normalizePublicPath('collections/abc.jpg'))->toBe('collections/abc.jpg')
        ->and(PublicMediaUrl::normalizePublicPath('storage/collections/abc.jpg'))->toBe('collections/abc.jpg')
        ->and(PublicMediaUrl::normalizePublicPath('/home/user/project/storage/app/public/collections/abc123.jpg'))
        ->toBe('collections/abc123.jpg')
        ->and(PublicMediaUrl::normalizePublicPath('C:\\Projects\\app\\storage\\app\\public\\collections\\x.jpg'))
        ->toBe('collections/x.jpg');

    $url = PublicMediaUrl::fromPublicPath('collections/abc.jpg');
    expect($url)->toStartWith('http')
        ->and($url)->toContain('/storage/collections/abc.jpg')
        ->and($url)->not->toContain('storage/app/public');
});
