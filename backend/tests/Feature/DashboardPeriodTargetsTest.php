<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Widgets\AdminDirectorBusinessPerformanceWidget;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Models\WeeklyTarget;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function periodTargetEmployee(string $name, string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Officer',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '23456789'.substr($mobile, -4),
        'pan_number' => 'ABCDE123'.substr($mobile, -1).'F',
        'bank_name' => 'Test Bank',
        'account_number' => '12345678901'.substr($mobile, -1),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

function periodTargetDealer(): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'Period Target Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9888888'.random_int(100, 999),
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

function periodTargetDirector(): User
{
    return User::query()->create([
        'name' => 'Period Director',
        'email' => 'period.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

it('resolves last week as the previous monday through sunday', function (): void {
    $range = app(DashboardMetricsService::class)->resolveDateRange('last_week');

    expect($range['label'])->toBe('Last Week')
        ->and($range['start']->toDateString())->toBe('2026-08-10')
        ->and($range['end']->toDateString())->toBe('2026-08-16')
        ->and(app(DashboardMetricsService::class)->periodHeading('week'))->toBe('This Week Performance')
        ->and(app(DashboardMetricsService::class)->periodHeading('last_week'))->toBe('Last Week Performance')
        ->and(app(DashboardMetricsService::class)->periodHeading('last_month'))->toBe('Last Month Performance')
        ->and(app(DashboardMetricsService::class)->periodHeading('today'))->toBe('Today Performance');
});

it('resolves last month as the previous calendar month', function (): void {
    $range = app(DashboardMetricsService::class)->resolveDateRange('last_month');

    expect($range['label'])->toBe('Last Month')
        ->and($range['start']->toDateString())->toBe('2026-07-01')
        ->and($range['end']->toDateString())->toBe('2026-07-31');
});

it('prorates sales and collection targets for today, this week, last week, and this month', function (): void {
    $employee = periodTargetEmployee('Period Sales', '9400000101');
    $dealer = periodTargetDealer();
    $director = periodTargetDirector();

    WeeklyTarget::query()->create([
        'employee_id' => $employee->id,
        'week_start_date' => '2026-08-01',
        'week_end_date' => '2026-08-31',
        'sales_target' => 310000,
        'collection_target' => 155000,
        'field_activity_target' => 31,
        'status' => 'active',
    ]);

    $todayOrder = Order::query()->create([
        'order_no' => 'ORD-PERIOD-TODAY',
        'order_date' => '2026-08-20',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 8000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 8000,
    ]);
    $lastWeekOrder = Order::query()->create([
        'order_no' => 'ORD-PERIOD-LAST-WEEK',
        'order_date' => '2026-08-12',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 3000,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 3000,
    ]);
    $todayOrder->forceFill(['updated_at' => '2026-08-20 10:00:00'])->saveQuietly();
    $lastWeekOrder->forceFill(['updated_at' => '2026-08-12 10:00:00'])->saveQuietly();

    Collection::query()->create([
        'collection_date' => '2026-08-20',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 2000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-PERIOD-1',
    ]);
    Collection::query()->create([
        'collection_date' => '2026-08-12',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 1000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-PERIOD-2',
    ]);

    $this->actingAs($director, 'sanctum');

    $this->getJson('/api/director/dashboard?period=today')
        ->assertOk()
        ->assertJsonPath('period', 'Today')
        ->assertJsonPath('company_summary.targets.sales_target', 10000)
        ->assertJsonPath('company_summary.targets.sales_achieved', 8000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 2000)
        ->assertJsonPath('company_summary.targets.sales_percentage', 80)
        ->assertJsonPath('company_summary.targets.collection_target', 5000)
        ->assertJsonPath('company_summary.targets.collection_achieved', 2000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 3000)
        ->assertJsonPath('company_summary.targets.collection_percentage', 40);

    $this->getJson('/api/director/dashboard?period=week')
        ->assertOk()
        ->assertJsonPath('period', 'This Week')
        ->assertJsonPath('company_summary.targets.sales_target', 40000)
        ->assertJsonPath('company_summary.targets.sales_achieved', 8000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 32000)
        ->assertJsonPath('company_summary.targets.sales_percentage', 20)
        ->assertJsonPath('company_summary.targets.collection_target', 20000)
        ->assertJsonPath('company_summary.targets.collection_achieved', 2000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 18000)
        ->assertJsonPath('company_summary.targets.collection_percentage', 10);

    $this->getJson('/api/director/dashboard?period=last_week')
        ->assertOk()
        ->assertJsonPath('period', 'Last Week')
        ->assertJsonPath('company_summary.targets.sales_target', 70000)
        ->assertJsonPath('company_summary.targets.sales_achieved', 3000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 67000)
        ->assertJsonPath('company_summary.targets.collection_target', 35000)
        ->assertJsonPath('company_summary.targets.collection_achieved', 1000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 34000);

    $this->getJson('/api/director/dashboard?period=month')
        ->assertOk()
        ->assertJsonPath('period', 'This Month')
        ->assertJsonPath('company_summary.targets.sales_target', 310000)
        ->assertJsonPath('company_summary.targets.sales_achieved', 11000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 299000)
        ->assertJsonPath('company_summary.targets.collection_target', 155000)
        ->assertJsonPath('company_summary.targets.collection_achieved', 3000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 152000);

    $this->getJson('/api/director/dashboard?period=custom&start_date=2026-08-17&end_date=2026-08-20')
        ->assertOk()
        ->assertJsonPath('period', 'Custom Range')
        ->assertJsonPath('company_summary.targets.sales_target', 40000)
        ->assertJsonPath('company_summary.targets.collection_target', 20000);
});

it('updates the director performance heading with the selected period', function (): void {
    $director = periodTargetDirector();

    Livewire::actingAs($director)
        ->test(AdminDirectorBusinessPerformanceWidget::class)
        ->assertSee('This Month Performance')
        ->call('setBizPeriod', 'weekly')
        ->assertSee('This Week Performance')
        ->call('setBizPeriod', 'last_week')
        ->assertSee('Last Week Performance')
        ->call('setBizPeriod', 'today')
        ->assertSee('Today Performance')
        ->call('setBizPeriod', 'custom')
        ->assertSee('Custom Performance');
});
