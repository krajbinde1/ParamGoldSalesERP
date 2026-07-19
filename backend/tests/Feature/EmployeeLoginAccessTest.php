<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\ResetEmployeePassword;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function loginAccessEmployeeData(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Login Access Test',
        'mobile' => '9876501234',
        'email' => 'loginaccess.test@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-16',
        'salary' => 20000,
        'base_location' => 'Pune',
        'daily_allowance' => 200,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 400,
        'aadhaar_number' => '234567890151',
        'pan_number' => 'ABCDE1235A',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789151',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ], $overrides);
}

it('creates an employee with mobile as default login id', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData());

    expect($result->employee->user?->login_id)->toBe('9876501234');
});

it('updates login id when employee mobile changes on edit', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData());

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, loginAccessEmployeeData([
        'mobile' => '9876501299',
        'email' => 'loginaccess.updated@example.com',
        'aadhaar_number' => '234567890151',
        'pan_number' => 'ABCDE1235A',
    ]));

    $result->employee->refresh();

    expect($result->employee->mobile)->toBe('9876501299')
        ->and($result->employee->user?->login_id)->toBe('9876501299');
});

it('resets password to mobile last four digits and revokes tokens', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData([
        'mobile' => '9876504321',
        'aadhaar_number' => '234567890152',
        'pan_number' => 'ABCDE1235B',
    ]));
    $user = $result->employee->user;
    $user->createToken('employee-mobile');
    $director = User::factory()->create([
        'employee_id' => null,
        'role' => UserRole::Director->value,
        'login_id' => '9999999902',
        'email' => 'director-reset-test@example.com',
    ]);

    $reset = app(ResetEmployeePassword::class)->execute(
        $result->employee,
        $director,
        ResetEmployeePassword::MODE_MOBILE_LAST_FOUR,
    );

    expect($reset['temporary_password'])->toBe('4321')
        ->and(Hash::check('4321', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->password_reset_by)->toBe($director->id);
});

it('resets password using a custom temporary password', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData([
        'mobile' => '9876504324',
        'aadhaar_number' => '234567890155',
        'pan_number' => 'ABCDE1235E',
    ]));
    $director = User::factory()->create([
        'employee_id' => null,
        'role' => UserRole::Director->value,
        'login_id' => '9999999905',
        'email' => 'director-custom-reset@example.com',
    ]);

    $reset = app(ResetEmployeePassword::class)->execute(
        $result->employee,
        $director,
        ResetEmployeePassword::MODE_CUSTOM,
        'Temp4324',
        'Temp4324',
    );

    expect($reset['temporary_password'])->toBe('Temp4324')
        ->and(Hash::check('Temp4324', $result->employee->user->fresh()->password))->toBeTrue();
});

it('blocks managers from resetting another manager password', function () {
    $target = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData([
        'mobile' => '9876504322',
        'email' => 'manager-target@example.com',
        'aadhaar_number' => '234567890153',
        'pan_number' => 'ABCDE1235C',
        'role' => UserRole::Manager->value,
    ]));
    $managerResult = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData([
        'mobile' => '9876504399',
        'email' => 'manager-actor@example.com',
        'aadhaar_number' => '234567890199',
        'pan_number' => 'ABCDE1299A',
        'role' => UserRole::Manager->value,
    ]));
    $manager = $managerResult->employee->user;

    expect(fn () => app(ResetEmployeePassword::class)->execute(
        $target->employee,
        $manager,
        ResetEmployeePassword::MODE_MOBILE_LAST_FOUR,
    ))->toThrow(AuthorizationException::class);

    app(DeleteEmployeeWithUserAccount::class)->execute($managerResult->employee);
});

it('allows login with updated mobile login id after mobile change', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(loginAccessEmployeeData([
        'mobile' => '9876504323',
        'aadhaar_number' => '234567890154',
        'pan_number' => 'ABCDE1235D',
    ]));
    $director = User::factory()->create([
        'employee_id' => null,
        'role' => UserRole::Director->value,
        'login_id' => '9999999904',
        'email' => 'director-api-test@example.com',
    ]);

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, loginAccessEmployeeData([
        'mobile' => '9876504398',
        'aadhaar_number' => '234567890154',
        'pan_number' => 'ABCDE1235D',
    ]));
    app(ResetEmployeePassword::class)->execute(
        $result->employee,
        $director,
        ResetEmployeePassword::MODE_MOBILE_LAST_FOUR,
    );

    $success = $this->postJson('/api/login', [
        'login_id' => '9876504398',
        'password' => '4398',
    ]);
    $old = $this->postJson('/api/login', [
        'login_id' => '9876504323',
        'password' => '4398',
    ]);

    expect($success->json('success'))->toBeTrue()
        ->and($old->json('success'))->toBeFalse();

    app(DeleteEmployeeWithUserAccount::class)->execute($result->employee);
});
