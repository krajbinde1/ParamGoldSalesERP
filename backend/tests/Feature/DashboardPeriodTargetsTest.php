<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Targets\SaveMonthlyTarget;
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

it('uses only assigned target records for the selected period without proration', function (): void {
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
        ->assertJsonPath('company_summary.targets.sales_target', 0)
        ->assertJsonPath('company_summary.targets.sales_achieved', 8000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 0)
        ->assertJsonPath('company_summary.targets.sales_percentage', 0)
        ->assertJsonPath('company_summary.targets.collection_target', 0)
        ->assertJsonPath('company_summary.targets.collection_achieved', 2000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 0)
        ->assertJsonPath('company_summary.targets.collection_percentage', 0);

    $this->getJson('/api/director/dashboard?period=week')
        ->assertOk()
        ->assertJsonPath('period', 'This Week')
        ->assertJsonPath('company_summary.targets.sales_target', 0)
        ->assertJsonPath('company_summary.targets.sales_achieved', 8000)
        ->assertJsonPath('company_summary.targets.collection_target', 0)
        ->assertJsonPath('company_summary.targets.collection_achieved', 2000);

    $this->getJson('/api/director/dashboard?period=last_week')
        ->assertOk()
        ->assertJsonPath('period', 'Last Week')
        ->assertJsonPath('company_summary.targets.sales_target', 0)
        ->assertJsonPath('company_summary.targets.sales_achieved', 3000)
        ->assertJsonPath('company_summary.targets.collection_target', 0)
        ->assertJsonPath('company_summary.targets.collection_achieved', 1000);

    $this->getJson('/api/director/dashboard?period=month')
        ->assertOk()
        ->assertJsonPath('period', 'This Month')
        ->assertJsonPath('company_summary.targets.sales_target', 310000)
        ->assertJsonPath('company_summary.targets.sales_achieved', 11000)
        ->assertJsonPath('company_summary.targets.sales_remaining', 299000)
        ->assertJsonPath('company_summary.targets.collection_target', 155000)
        ->assertJsonPath('company_summary.targets.collection_achieved', 3000)
        ->assertJsonPath('company_summary.targets.collection_remaining', 152000);

    $this->getJson('/api/director/dashboard?period=last_month')
        ->assertOk()
        ->assertJsonPath('period', 'Last Month')
        ->assertJsonPath('company_summary.targets.sales_target', 0)
        ->assertJsonPath('company_summary.targets.collection_target', 0);

    $this->getJson('/api/director/dashboard?period=custom&start_date=2026-08-17&end_date=2026-08-20')
        ->assertOk()
        ->assertJsonPath('period', 'Custom Range')
        ->assertJsonPath('company_summary.targets.sales_target', 0)
        ->assertJsonPath('company_summary.targets.collection_target', 0)
        ->assertJsonPath('company_summary.targets.sales_achieved', 8000);
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

it('uses this week linked weekly target and this month monthly total without carrying other periods', function (): void {
    $employee = periodTargetEmployee('Linked Week Sales', '9400000102');
    $director = periodTargetDirector();
    $metrics = app(DashboardMetricsService::class);

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-08-01',
        'sales_target' => 310000,
        'collection_target' => 155000,
        'field_activity_target' => 31,
        'status' => 'active',
    ]);

    $thisWeek = $monthly->weeklyTargets()
        ->whereDate('week_start_date', '2026-08-17')
        ->whereDate('week_end_date', '2026-08-23')
        ->first();
    $lastWeek = $monthly->weeklyTargets()
        ->whereDate('week_start_date', '2026-08-10')
        ->whereDate('week_end_date', '2026-08-16')
        ->first();

    expect($thisWeek)->not->toBeNull()
        ->and($lastWeek)->not->toBeNull();

    $weekRange = $metrics->resolveDateRange('week');
    $lastWeekRange = $metrics->resolveDateRange('last_week');
    $monthRange = $metrics->resolveDateRange('month');
    $lastMonthRange = $metrics->resolveDateRange('last_month');
    $customRange = $metrics->resolveDateRange('custom', '2026-08-17', '2026-08-20');

    $weekTargets = $metrics->targetSummaryForPeriod($employee->id, $weekRange['start'], $weekRange['end'], 'week');
    $lastWeekTargets = $metrics->targetSummaryForPeriod($employee->id, $lastWeekRange['start'], $lastWeekRange['end'], 'last_week');
    $monthTargets = $metrics->targetSummaryForPeriod($employee->id, $monthRange['start'], $monthRange['end'], 'month');
    $lastMonthTargets = $metrics->targetSummaryForPeriod($employee->id, $lastMonthRange['start'], $lastMonthRange['end'], 'last_month');
    $customTargets = $metrics->targetSummaryForPeriod($employee->id, $customRange['start'], $customRange['end'], 'custom');

    expect($weekTargets['sales_target'])->toBe((float) $thisWeek->sales_target)
        ->and($weekTargets['collection_target'])->toBe((float) $thisWeek->collection_target)
        ->and($weekTargets['field_activity_target'])->toBe((int) $thisWeek->field_activity_target)
        ->and($lastWeekTargets['sales_target'])->toBe((float) $lastWeek->sales_target)
        ->and($monthTargets['sales_target'])->toBe(310000.0)
        ->and($monthTargets['collection_target'])->toBe(155000.0)
        ->and($monthTargets['field_activity_target'])->toBe(31)
        ->and($lastMonthTargets['sales_target'])->toBe(0.0)
        ->and($customTargets['sales_target'])->toBe(0.0)
        ->and($weekTargets['sales_target'])->not->toBe(310000.0);

    $this->actingAs($director, 'sanctum');

    $weekJson = $this->getJson('/api/director/dashboard?period=week')->assertOk();
    $monthJson = $this->getJson('/api/director/dashboard?period=month')->assertOk();

    expect((float) $weekJson->json('company_summary.targets.sales_target'))->toBe((float) $thisWeek->sales_target)
        ->and((float) $monthJson->json('company_summary.targets.sales_target'))->toBe(310000.0);
});

