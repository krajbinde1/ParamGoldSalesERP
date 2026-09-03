<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Targets\SaveMonthlyTarget;
use App\Enums\UserRole;
use App\Filament\Pages\TeamPerformance;
use App\Filament\Widgets\AdminDirectorBusinessPerformanceWidget;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\FieldActivity;
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

function periodBoundaryOrder(int $employeeId, int $dealerId, string $orderNo, string $orderDate, float $amount): Order
{
    $order = Order::query()->create([
        'order_no' => $orderNo,
        'order_date' => $orderDate,
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => $amount,
    ]);
    $order->forceFill(['updated_at' => $orderDate.' 10:00:00'])->saveQuietly();

    return $order;
}

function periodBoundaryFieldActivity(int $employeeId, string $activityDate, string $farmerName): FieldActivity
{
    return FieldActivity::query()->create([
        'employee_id' => $employeeId,
        'farmer_name' => $farmerName,
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_type' => 'farmer_visit',
        'remark' => $farmerName,
        'activity_date' => $activityDate,
        'activity_time' => '11:00:00',
        'photo_path' => 'field-activities/'.strtolower(str_replace(' ', '-', $farmerName)).'.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);
}

it('clips this week to the current month when the calendar week starts in the previous month', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Kolkata'));

    $metrics = app(DashboardMetricsService::class);
    $week = $metrics->resolveDateRange('week');
    $lastWeek = $metrics->resolveDateRange('last_week');

    expect($week['start']->toDateString())->toBe('2026-09-01')
        ->and($week['end']->toDateString())->toBe('2026-09-03')
        ->and($lastWeek['start']->toDateString())->toBe('2026-08-24')
        ->and($lastWeek['end']->toDateString())->toBe('2026-08-30');

    $director = periodTargetDirector();

    Livewire::actingAs($director)
        ->test(TeamPerformance::class)
        ->assertSuccessful()
        ->assertSee('This Week · 01 Sep 2026 – 03 Sep 2026')
        ->call('setPeriod', 'last_week')
        ->assertSee('Last Week · 24 Aug 2026 – 30 Aug 2026');
});

it('clips last week to the month that contains that sunday', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00', 'Asia/Kolkata'));

    $metrics = app(DashboardMetricsService::class);
    $week = $metrics->resolveDateRange('week');
    $lastWeek = $metrics->resolveDateRange('last_week');

    expect($week['start']->toDateString())->toBe('2026-09-07')
        ->and($week['end']->toDateString())->toBe('2026-09-07')
        ->and($lastWeek['start']->toDateString())->toBe('2026-09-01')
        ->and($lastWeek['end']->toDateString())->toBe('2026-09-06');
});

it('uses the month-clipped week for weekly target and sales collection and field activity', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Kolkata'));

    $employee = periodTargetEmployee('Month Boundary Sales', '9400000103');
    $dealer = periodTargetDealer();
    $director = periodTargetDirector();
    $metrics = app(DashboardMetricsService::class);

    $august = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-08-01',
        'sales_target' => 310000,
        'collection_target' => 155000,
        'field_activity_target' => 31,
        'status' => 'active',
    ]);
    $september = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 300000,
        'collection_target' => 150000,
        'field_activity_target' => 30,
        'status' => 'active',
    ]);

    $augustLastDay = $august->weeklyTargets()
        ->whereDate('week_start_date', '2026-08-31')
        ->whereDate('week_end_date', '2026-08-31')
        ->first();
    $septemberFirstWeek = $september->weeklyTargets()
        ->whereDate('week_start_date', '2026-09-01')
        ->whereDate('week_end_date', '2026-09-06')
        ->first();

    expect($augustLastDay)->not->toBeNull()
        ->and($septemberFirstWeek)->not->toBeNull();

    periodBoundaryOrder($employee->id, $dealer->id, 'ORD-AUG-31', '2026-08-31', 11111);
    periodBoundaryOrder($employee->id, $dealer->id, 'ORD-SEP-01', '2026-09-01', 20000);
    periodBoundaryOrder($employee->id, $dealer->id, 'ORD-SEP-03', '2026-09-03', 30000);

    Collection::query()->create([
        'collection_date' => '2026-08-31',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 4000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-AUG-31',
    ]);
    Collection::query()->create([
        'collection_date' => '2026-09-02',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 7000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-SEP-02',
    ]);

    periodBoundaryFieldActivity($employee->id, '2026-08-31', 'Aug 31 farmer');
    periodBoundaryFieldActivity($employee->id, '2026-09-03', 'Sep 3 farmer');

    $weekRange = $metrics->resolveDateRange('week');
    $row = $metrics->employeePerformanceRow($employee, $weekRange['start'], $weekRange['end'], 'week');

    expect($row['sales_target'])->toBe((float) $septemberFirstWeek->sales_target)
        ->and($row['sales_target'])->not->toBe((float) $augustLastDay->sales_target)
        ->and($row['sales_target'])->not->toBe((float) $septemberFirstWeek->sales_target + (float) $augustLastDay->sales_target)
        ->and($row['collection_target'])->toBe((float) $septemberFirstWeek->collection_target)
        ->and($row['field_activity_target'])->toBe((int) $septemberFirstWeek->field_activity_target)
        ->and($row['sales_achieved'])->toBe(50000.0)
        ->and($row['collection_achieved'])->toBe(7000.0)
        ->and($row['field_activity_achieved'])->toBe(1);

    $salesOrders = collect($metrics->employeeOrdersForPeriod($employee->id, $weekRange['start'], $weekRange['end']));
    $collections = collect($metrics->employeeCollectionsForPeriod($employee->id, $weekRange['start'], $weekRange['end']));
    $activities = collect($metrics->employeeFieldActivitiesForPeriod($employee->id, $weekRange['start'], $weekRange['end']));

    expect($salesOrders->pluck('order_no')->all())->toBe(['ORD-SEP-03', 'ORD-SEP-01'])
        ->and($collections->pluck('amount')->all())->toBe([7000.0])
        ->and($activities)->toHaveCount(1)
        ->and($activities->first()['farmer_name'])->toBe('Sep 3 farmer');

    $this->actingAs($director, 'sanctum');

    $weekJson = $this->getJson('/api/director/dashboard?period=week')->assertOk();

    expect($weekJson->json('period'))->toBe('This Week')
        ->and((float) $weekJson->json('company_summary.targets.sales_target'))->toBe((float) $septemberFirstWeek->sales_target)
        ->and((float) $weekJson->json('company_summary.targets.sales_achieved'))->toBe(50000.0)
        ->and((float) $weekJson->json('company_summary.targets.collection_target'))->toBe((float) $septemberFirstWeek->collection_target)
        ->and((float) $weekJson->json('company_summary.targets.collection_achieved'))->toBe(7000.0);

    Livewire::actingAs($director)
        ->test(TeamPerformance::class)
        ->assertSuccessful()
        ->assertSee('This Week · 01 Sep 2026 – 03 Sep 2026')
        ->assertSee('Month Boundary Sales')
        ->assertDontSee('ORD-AUG-31');
});

