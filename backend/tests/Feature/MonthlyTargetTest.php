<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Targets\SaveMonthlyTarget;
use App\Enums\UserRole;
use App\Filament\Resources\Targets\Pages\CreateWeeklyTarget;
use App\Filament\Resources\Targets\Pages\EditWeeklyTarget;
use App\Filament\Resources\Targets\Pages\ListWeeklyTargets;
use App\Models\Employee;
use App\Models\MonthlyTarget;
use App\Models\User;
use App\Models\WeeklyTarget;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Targets\MonthlyTargetWeekSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function monthlyTargetEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Target Employee '.$mobile,
        'mobile' => $mobile,
        'email' => 'target.'.$mobile.'@example.com',
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

function monthlyTargetAdmin(): User
{
    return User::query()->create([
        'name' => 'Target Admin',
        'email' => 'target.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

it('splits a month into monday-sunday weeks clipped to the month', function (): void {
    $weeks = app(MonthlyTargetWeekSplitter::class)->weeksForMonth('2026-09-01');

    expect(array_map(fn (array $week): array => [
        'start' => $week['start']->toDateString(),
        'end' => $week['end']->toDateString(),
        'days' => $week['days'],
    ], $weeks))->toBe([
        ['start' => '2026-09-01', 'end' => '2026-09-06', 'days' => 6],
        ['start' => '2026-09-07', 'end' => '2026-09-13', 'days' => 7],
        ['start' => '2026-09-14', 'end' => '2026-09-20', 'days' => 7],
        ['start' => '2026-09-21', 'end' => '2026-09-27', 'days' => 7],
        ['start' => '2026-09-28', 'end' => '2026-09-30', 'days' => 3],
    ])->and(array_sum(array_column($weeks, 'days')))->toBe(30);
});

it('creates weekly targets that cover the month and sum exactly to the monthly totals', function (): void {
    $employee = monthlyTargetEmployee('9400000101');

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-15',
        'sales_target' => 30000,
        'collection_target' => 15000.50,
        'field_activity_target' => 11,
        'status' => 'active',
        'remark' => 'September plan',
    ]);

    $weeks = $monthly->weeklyTargets()->orderBy('week_start_date')->get();

    expect($monthly->month_start_date->toDateString())->toBe('2026-09-01')
        ->and($weeks)->toHaveCount(5)
        ->and((float) $weeks->sum('sales_target'))->toBe(30000.0)
        ->and((float) $weeks->sum('collection_target'))->toBe(15000.50)
        ->and((int) $weeks->sum('field_activity_target'))->toBe(11)
        ->and($weeks->every(fn (WeeklyTarget $week): bool => $week->monthly_target_id === $monthly->id))->toBeTrue()
        ->and($weeks->first()->week_start_date->toDateString())->toBe('2026-09-01')
        ->and($weeks->last()->week_end_date->toDateString())->toBe('2026-09-30')
        ->and((float) $weeks->first()->sales_target)->toBe(6000.0);

    $coveredDays = 0;
    $previousEnd = null;
    foreach ($weeks as $week) {
        $coveredDays += (int) $week->week_start_date->diffInDays($week->week_end_date) + 1;
        if ($previousEnd !== null) {
            expect($week->week_start_date->toDateString())->toBe($previousEnd->copy()->addDay()->toDateString());
        }
        $previousEnd = $week->week_end_date;
    }

    expect($coveredDays)->toBe(30);
});

it('recalculates weekly targets when the monthly target is edited', function (): void {
    $employee = monthlyTargetEmployee('9400000102');

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 30000,
        'collection_target' => 10000,
        'field_activity_target' => 10,
        'status' => 'active',
    ]);

    $originalIds = $monthly->weeklyTargets()->orderBy('week_start_date')->pluck('id')->all();

    $updated = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 31000,
        'collection_target' => 20000,
        'field_activity_target' => 20,
        'status' => 'active',
        'remark' => 'Revised',
    ], $monthly);

    $weeks = $updated->weeklyTargets()->orderBy('week_start_date')->get();

    expect($updated->id)->toBe($monthly->id)
        ->and($weeks->pluck('id')->all())->toBe($originalIds)
        ->and((float) $weeks->sum('sales_target'))->toBe(31000.0)
        ->and((float) $weeks->sum('collection_target'))->toBe(20000.0)
        ->and((int) $weeks->sum('field_activity_target'))->toBe(20)
        ->and($weeks->first()->remark)->toBe('Revised');
});

it('rejects a second monthly target for the same employee and month', function (): void {
    $employee = monthlyTargetEmployee('9400000103');

    app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 1000,
        'collection_target' => 1000,
        'field_activity_target' => 1,
        'status' => 'active',
    ]);

    expect(fn () => app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 2000,
        'collection_target' => 2000,
        'field_activity_target' => 2,
        'status' => 'active',
    ]))->toThrow(ValidationException::class);
});

it('rejects a monthly target that overlaps an existing weekly target', function (): void {
    $employee = monthlyTargetEmployee('9400000104');

    WeeklyTarget::query()->create([
        'employee_id' => $employee->id,
        'week_start_date' => '2026-09-07',
        'week_end_date' => '2026-09-13',
        'sales_target' => 500,
        'collection_target' => 500,
        'field_activity_target' => 1,
        'status' => 'active',
    ]);

    expect(fn () => app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 30000,
        'collection_target' => 10000,
        'field_activity_target' => 10,
        'status' => 'active',
    ]))->toThrow(ValidationException::class);
});

it('lets admin create a monthly target from the targets form', function (): void {
    $admin = monthlyTargetAdmin();
    $employee = monthlyTargetEmployee('9400000105');

    Livewire::actingAs($admin)
        ->test(CreateWeeklyTarget::class)
        ->fillForm([
            'target_type' => MonthlyTarget::TYPE,
            'employee_id' => $employee->id,
            'status' => 'active',
            'month_start_date' => '2026-09-01',
            'sales_target' => 30000,
            'collection_target' => 12000,
            'field_activity_target' => 10,
            'remark' => 'From admin form',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $monthly = MonthlyTarget::query()->where('employee_id', $employee->id)->first();

    expect($monthly)->not->toBeNull()
        ->and(WeeklyTarget::query()->where('monthly_target_id', $monthly->id)->count())->toBe(5)
        ->and((float) WeeklyTarget::query()->where('monthly_target_id', $monthly->id)->sum('sales_target'))->toBe(30000.0);
});

it('recalculates weekly targets when a monthly split week is edited', function (): void {
    $admin = monthlyTargetAdmin();
    $employee = monthlyTargetEmployee('9400000106');

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $employee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 30000,
        'collection_target' => 10000,
        'field_activity_target' => 10,
        'status' => 'active',
    ]);

    $week = $monthly->weeklyTargets()->orderBy('week_start_date')->first();

    Livewire::actingAs($admin)
        ->test(EditWeeklyTarget::class, ['record' => $week->getKey()])
        ->fillForm([
            'target_type' => MonthlyTarget::TYPE,
            'employee_id' => $employee->id,
            'status' => 'active',
            'month_start_date' => '2026-09-01',
            'sales_target' => 45000,
            'collection_target' => 18000,
            'field_activity_target' => 15,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $monthly->fresh()->sales_target)->toBe(45000.0)
        ->and((float) WeeklyTarget::query()->where('monthly_target_id', $monthly->id)->sum('sales_target'))->toBe(45000.0)
        ->and((int) WeeklyTarget::query()->where('monthly_target_id', $monthly->id)->sum('field_activity_target'))->toBe(15);
});

it('lists targets by from date newest first and latest created first on the same date', function (): void {
    $admin = monthlyTargetAdmin();
    $olderEmployee = monthlyTargetEmployee('9400000201');
    $newerSameDateEmployee = monthlyTargetEmployee('9400000202');
    $latestDateEmployee = monthlyTargetEmployee('9400000203');

    Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'Asia/Kolkata'));
    $olderSameDate = WeeklyTarget::query()->create([
        'employee_id' => $olderEmployee->id,
        'week_start_date' => '2026-08-10',
        'week_end_date' => '2026-08-16',
        'sales_target' => 1000,
        'collection_target' => 1000,
        'field_activity_target' => 1,
        'status' => 'active',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-03 11:00:00', 'Asia/Kolkata'));
    $newerSameDate = WeeklyTarget::query()->create([
        'employee_id' => $newerSameDateEmployee->id,
        'week_start_date' => '2026-08-10',
        'week_end_date' => '2026-08-16',
        'sales_target' => 2000,
        'collection_target' => 2000,
        'field_activity_target' => 2,
        'status' => 'active',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-03 12:00:00', 'Asia/Kolkata'));
    $latestDate = WeeklyTarget::query()->create([
        'employee_id' => $latestDateEmployee->id,
        'week_start_date' => '2026-09-01',
        'week_end_date' => '2026-09-07',
        'sales_target' => 3000,
        'collection_target' => 3000,
        'field_activity_target' => 3,
        'status' => 'active',
    ]);

    $page = Livewire::actingAs($admin)->test(ListWeeklyTargets::class);

    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([
        $latestDate->id,
        $newerSameDate->id,
        $olderSameDate->id,
    ]);

    Carbon::setTestNow();
});

it('deletes a weekly target from the list after confirmation and drops it from performance totals', function (): void {
    $admin = monthlyTargetAdmin();
    $employee = monthlyTargetEmployee('9400000204');

    $target = WeeklyTarget::query()->create([
        'employee_id' => $employee->id,
        'week_start_date' => '2026-09-01',
        'week_end_date' => '2026-09-07',
        'sales_target' => 8000,
        'collection_target' => 4000,
        'field_activity_target' => 4,
        'status' => 'active',
    ]);

    $metrics = app(DashboardMetricsService::class);
    $start = Carbon::parse('2026-09-01', 'Asia/Kolkata')->startOfDay();
    $end = Carbon::parse('2026-09-07', 'Asia/Kolkata')->endOfDay();

    expect($metrics->targetSummaryForPeriod($employee->id, $start, $end)['sales_target'])->toBe(8000.0);

    $page = Livewire::actingAs($admin)
        ->test(ListWeeklyTargets::class)
        ->assertTableActionVisible('view', $target)
        ->assertTableActionVisible('edit', $target)
        ->assertTableActionVisible('delete', $target)
        ->mountTableAction('delete', $target);

    expect($page->instance()->getMountedTableAction()?->getModalHeading())
        ->toBe('Are you sure you want to delete this target?');

    $page->callMountedTableAction()->assertHasNoTableActionErrors();

    expect(WeeklyTarget::query()->find($target->id))->toBeNull()
        ->and($metrics->targetSummaryForPeriod($employee->id, $start, $end)['sales_target'])->toBe(0.0);
});

it('deletes a monthly target and its auto-generated weeks without removing unrelated weekly targets', function (): void {
    $admin = monthlyTargetAdmin();
    $monthlyEmployee = monthlyTargetEmployee('9400000205');
    $unrelatedEmployee = monthlyTargetEmployee('9400000206');

    $monthly = app(SaveMonthlyTarget::class)->execute([
        'employee_id' => $monthlyEmployee->id,
        'month_start_date' => '2026-09-01',
        'sales_target' => 30000,
        'collection_target' => 12000,
        'field_activity_target' => 10,
        'status' => 'active',
    ]);

    $unrelated = WeeklyTarget::query()->create([
        'employee_id' => $unrelatedEmployee->id,
        'week_start_date' => '2026-09-07',
        'week_end_date' => '2026-09-13',
        'sales_target' => 5000,
        'collection_target' => 2500,
        'field_activity_target' => 2,
        'status' => 'active',
    ]);

    $generatedWeek = $monthly->weeklyTargets()->orderBy('week_start_date')->first();
    $generatedIds = $monthly->weeklyTargets()->pluck('id')->all();

    expect($generatedWeek)->not->toBeNull()
        ->and($generatedIds)->toHaveCount(5);

    $metrics = app(DashboardMetricsService::class);
    $monthStart = Carbon::parse('2026-09-01', 'Asia/Kolkata')->startOfDay();
    $monthEnd = Carbon::parse('2026-09-30', 'Asia/Kolkata')->endOfDay();

    expect($metrics->targetSummaryForPeriod($monthlyEmployee->id, $monthStart, $monthEnd)['sales_target'])->toBe(30000.0)
        ->and($metrics->targetSummaryForPeriod($unrelatedEmployee->id, $monthStart, $monthEnd)['sales_target'])->toBe(5000.0);

    $page = Livewire::actingAs($admin)
        ->test(ListWeeklyTargets::class)
        ->mountTableAction('delete', $generatedWeek);

    expect($page->instance()->getMountedTableAction()?->getModalHeading())
        ->toBe('Are you sure you want to delete this target?');

    $page->callMountedTableAction()->assertHasNoTableActionErrors();

    expect(MonthlyTarget::query()->find($monthly->id))->toBeNull()
        ->and(WeeklyTarget::query()->whereIn('id', $generatedIds)->count())->toBe(0)
        ->and(WeeklyTarget::query()->find($unrelated->id))->not->toBeNull()
        ->and($metrics->targetSummaryForPeriod($monthlyEmployee->id, $monthStart, $monthEnd)['sales_target'])->toBe(0.0)
        ->and($metrics->targetSummaryForPeriod($unrelatedEmployee->id, $monthStart, $monthEnd)['sales_target'])->toBe(5000.0);
});
