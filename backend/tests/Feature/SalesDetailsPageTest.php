<?php

use App\Enums\UserRole;
use App\Filament\Pages\SalesDetails;
use App\Filament\Widgets\AdminDirectorWelcomeWidget;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Support\AttendanceCalendar;
use App\Support\IndianCurrency;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-13 12:00:00', AttendanceCalendar::TIMEZONE));
    app()->forgetInstance(DirectorDashboardDataService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function salesDetailsDirector(): User
{
    return User::query()->create([
        'name' => 'Sales Details Director',
        'email' => 'sales.details.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function salesDetailsEmployee(string $name, string $mobile): Employee
{
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
        'aadhaar_number' => str_pad((string) random_int(100000000000, 999999999999), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'SD'.strtoupper(substr(uniqid(), -8)).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) random_int(100000000000, 999999999999), 12, '0', STR_PAD_LEFT),
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

function salesDetailsDealer(string $firm): Dealer
{
    return Dealer::query()->create([
        'firm_name' => $firm,
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'outstanding' => 0,
    ]);
}

function salesDetailsOrder(int $employeeId, int $dealerId, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => AttendanceCalendar::today()->toDateString(),
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => Order::STATUS_APPROVED,
        'payment_type' => 'Credit',
        'subtotal' => 100000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100000,
    ], $overrides));
}

it('opens sales details from the today sales card and matches the dashboard total', function (): void {
    $director = salesDetailsDirector();
    $employee = salesDetailsEmployee('Sales Card Exec', '9912000001');
    $alpha = salesDetailsDealer('Alpha Traders');
    $beta = salesDetailsDealer('Beta Agency');
    $today = AttendanceCalendar::today()->toDateString();
    $yesterday = AttendanceCalendar::today()->copy()->subDay()->toDateString();

    salesDetailsOrder($employee->id, $alpha->id, [
        'order_date' => $today,
        'bill_number' => 'INV-A-1',
        'grand_total' => 250000,
        'status' => Order::STATUS_BILLED,
    ]);
    salesDetailsOrder($employee->id, $alpha->id, [
        'order_date' => $today,
        'bill_number' => 'INV-A-2',
        'grand_total' => 150000,
        'status' => Order::STATUS_DISPATCHED,
    ]);
    salesDetailsOrder($employee->id, $beta->id, [
        'order_date' => $today,
        'bill_number' => 'INV-B-1',
        'grand_total' => 80000,
        'status' => Order::STATUS_APPROVED,
    ]);
    salesDetailsOrder($employee->id, $alpha->id, [
        'order_date' => $yesterday,
        'bill_number' => 'INV-Y-1',
        'grand_total' => 999999,
        'status' => Order::STATUS_DISPATCHED,
    ]);
    salesDetailsOrder($employee->id, $beta->id, [
        'order_date' => $today,
        'grand_total' => 50000,
        'status' => Order::STATUS_REJECTED,
    ]);

    $range = app(DashboardMetricsService::class)->resolveDateRange('today');
    $sales = app(DirectorDashboardDataService::class);
    $snapshot = $sales->snapshot($director);
    $details = $sales->dashboardSalesPartyDetails($range['start'], $range['end']);

    expect($snapshot['today_sales'])->toBe(480000.0)
        ->and($details['total_sales'])->toBe(480000.0)
        ->and($details['total_invoices'])->toBe(3)
        ->and($details['total_parties'])->toBe(2)
        ->and($details['total_sales'])->toBe($sales->dashboardSalesTotal($range['start'], $range['end']));

    Livewire::actingAs($director)
        ->test(AdminDirectorWelcomeWidget::class)
        ->assertSuccessful()
        ->assertSeeHtml(SalesDetails::getUrl(['period' => 'today']))
        ->assertDontSeeHtml('filters%5Border_date%5D');

    $page = Livewire::actingAs($director)
        ->test(SalesDetails::class, ['period' => 'today'])
        ->assertSuccessful()
        ->assertSee('Sales Details')
        ->assertSee('Total Sales Amount')
        ->assertSee(IndianCurrency::format(480000))
        ->assertSee('Total Invoices')
        ->assertSee('3')
        ->assertSee('Total Parties')
        ->assertSee('2')
        ->assertSee('Alpha Traders')
        ->assertSee('2 invoices')
        ->assertSee('Beta Agency')
        ->assertSee('INV-B-1')
        ->assertDontSee('INV-Y-1')
        ->assertDontSee('INV-A-1');

    expect((float) $page->instance()->details()['total_sales'])->toBe(480000.0);

    $page->call('toggleParty', $alpha->id)
        ->assertSee('INV-A-1')
        ->assertSee('INV-A-2');
});

it('filters sales details by custom date range using the same sales source', function (): void {
    $director = salesDetailsDirector();
    $employee = salesDetailsEmployee('Range Sales Exec', '9912000002');
    $dealer = salesDetailsDealer('Range Dealer');

    salesDetailsOrder($employee->id, $dealer->id, [
        'order_date' => '2026-09-10',
        'bill_number' => 'INV-RANGE-1',
        'grand_total' => 120000,
    ]);
    salesDetailsOrder($employee->id, $dealer->id, [
        'order_date' => '2026-09-12',
        'bill_number' => 'INV-RANGE-2',
        'grand_total' => 30000,
    ]);
    salesDetailsOrder($employee->id, $dealer->id, [
        'order_date' => '2026-09-01',
        'bill_number' => 'INV-OUT',
        'grand_total' => 70000,
    ]);

    $start = Carbon::parse('2026-09-10', AttendanceCalendar::TIMEZONE)->startOfDay();
    $end = Carbon::parse('2026-09-12', AttendanceCalendar::TIMEZONE)->endOfDay();
    $expected = app(DirectorDashboardDataService::class)->dashboardSalesTotal($start, $end);

    expect($expected)->toBe(150000.0);

    $page = Livewire::actingAs($director)
        ->test(SalesDetails::class)
        ->call('setPeriod', 'custom')
        ->set('customFromDate', '2026-09-10')
        ->set('customToDate', '2026-09-12')
        ->call('applyCustomPeriod')
        ->assertSuccessful()
        ->assertSee('2 invoices')
        ->call('toggleParty', $dealer->id)
        ->assertSee('INV-RANGE-1')
        ->assertSee('INV-RANGE-2')
        ->assertDontSee('INV-OUT');

    expect((float) $page->instance()->details()['total_sales'])->toBe($expected);
});

it('hides sales details from managers', function (): void {
    $manager = User::query()->create([
        'name' => 'Sales Details Manager',
        'email' => 'sales.details.manager.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Manager->value,
    ]);

    $this->actingAs($manager);
    expect(SalesDetails::canAccess())->toBeFalse();
});
