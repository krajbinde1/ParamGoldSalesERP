<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\RepairTestManagerEmployee;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function repairTestManagerOtherEmployee(string $mobile, string $name, ?int $reportingManagerId = null): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Officer',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '2'.str_pad(substr($mobile, -11), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ZZZDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => '9'.substr($mobile, -11),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
        'reporting_manager_id' => $reportingManagerId,
    ])->employee;
}

it('links the existing Test Manager user to a new employee and assigns only the test sales employee', function (): void {
    $user = User::query()->create([
        'name' => 'Test Manager',
        'email' => RepairTestManagerEmployee::MANAGER_EMAIL,
        'login_id' => RepairTestManagerEmployee::MANAGER_LOGIN_ID,
        'password' => Hash::make('KeepThisPassword1!'),
        'role' => UserRole::Manager->value,
        'employee_id' => null,
    ]);

    $mangesh = repairTestManagerOtherEmployee('9545127497', 'Mangesh Gavhane');
    $mangesh->user?->update(['role' => UserRole::Manager->value]);
    $report = repairTestManagerOtherEmployee('9604326216', 'Ganesh Dere', $mangesh->id);

    $result = app(RepairTestManagerEmployee::class)->execute();

    expect($result['manager_user']->id)->toBe($user->id)
        ->and($result['manager_user']->employee_id)->toBe($result['manager_employee']->id)
        ->and($result['manager_user']->role)->toBe(UserRole::Manager->value)
        ->and($result['manager_user']->login_id)->toBe(RepairTestManagerEmployee::MANAGER_LOGIN_ID)
        ->and(Hash::check('KeepThisPassword1!', $result['manager_user']->password))->toBeTrue()
        ->and($result['manager_employee']->status)->toBeTrue()
        ->and($result['manager_employee']->full_name)->toBe(RepairTestManagerEmployee::MANAGER_NAME)
        ->and($result['sales_employee']->reporting_manager_id)->toBe($result['manager_employee']->id)
        ->and($report->fresh()->reporting_manager_id)->toBe($mangesh->id)
        ->and($mangesh->fresh()->reporting_manager_id)->toBeNull()
        ->and(Employee::query()->where('reporting_manager_id', $mangesh->id)->pluck('id')->all())
        ->toBe([$report->id]);

    expect(
        Employee::query()
            ->where('status', true)
            ->whereKey($result['manager_employee']->id)
            ->exists(),
    )->toBeTrue();
});

it('is idempotent and does not retarget other reporting managers on a second run', function (): void {
    User::query()->create([
        'name' => 'Test Manager',
        'email' => RepairTestManagerEmployee::MANAGER_EMAIL,
        'login_id' => RepairTestManagerEmployee::MANAGER_LOGIN_ID,
        'password' => Hash::make('password'),
        'role' => UserRole::Manager->value,
        'employee_id' => null,
    ]);

    $first = app(RepairTestManagerEmployee::class)->execute();
    $second = app(RepairTestManagerEmployee::class)->execute();

    expect($second['manager_employee']->id)->toBe($first['manager_employee']->id)
        ->and($second['manager_user']->id)->toBe($first['manager_user']->id)
        ->and($second['sales_employee']->id)->toBe($first['sales_employee']->id)
        ->and($second['sales_employee']->reporting_manager_id)->toBe($first['manager_employee']->id)
        ->and(User::query()->where('login_id', RepairTestManagerEmployee::MANAGER_LOGIN_ID)->count())->toBe(1);
});
