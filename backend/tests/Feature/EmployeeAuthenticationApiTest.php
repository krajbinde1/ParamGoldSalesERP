<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use Illuminate\Support\Facades\Hash;

function employeeForAuthentication(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Mobile Employee',
        'mobile' => '9145433002',
        'email' => 'mobile.employee@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-11',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '234567890123',
        'pan_number' => 'ABCDE1234F',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789012',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides);
}

it('logs in an active employee with valid credentials', function () {
    app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $response = $this->postJson('/api/login', [
        'login_id' => '9145433002',
        'password' => '3002',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('user.login_id', '9145433002')
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonPath('employee.employee_code', 'E001')
        ->assertJsonStructure(['token']);
});

it('rejects an invalid password', function () {
    app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->postJson('/api/login', [
        'login_id' => '9145433002',
        'password' => 'wrong',
    ])->assertUnprocessable()->assertJsonPath('success', false);
});

it('blocks login for an inactive employee', function () {
    app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication(['status' => false]));

    $this->postJson('/api/login', [
        'login_id' => '9145433002',
        'password' => '3002',
    ])->assertForbidden();
});

it('returns the linked employee from me', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->actingAs($result->employee->user, 'sanctum')
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('employee.id', $result->employee->id)
        ->assertJsonPath('employee.mobile', '9145433002');
});

it('rejects unauthenticated protected requests', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/employee/dashboard')->assertUnauthorized();
});

it('changes the password and clears the first login requirement', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->actingAs($result->employee->user, 'sanctum')
        ->postJson('/api/change-password', [
            'current_password' => '3002',
            'password' => 'NewSecure@123',
            'password_confirmation' => 'NewSecure@123',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

    $user = $result->employee->user->fresh();
    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('NewSecure@123', $user->password))->toBeTrue();
});

it('keeps must change password true until password change succeeds', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->actingAs($result->employee->user, 'sanctum')
        ->postJson('/api/change-password', [
            'current_password' => 'incorrect',
            'password' => 'NewSecure@123',
            'password_confirmation' => 'NewSecure@123',
        ])->assertUnprocessable();

    expect($result->employee->user->fresh()->must_change_password)->toBeTrue();
});

it('revokes only the current token on logout', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());
    $user = $result->employee->user;
    $first = $user->createToken('employee-mobile')->plainTextToken;
    $user->createToken('other-device');

    $this->withToken($first)->postJson('/api/logout')->assertOk();

    expect($user->tokens()->where('name', 'employee-mobile')->exists())->toBeFalse()
        ->and($user->tokens()->where('name', 'other-device')->exists())->toBeTrue();
});

it('returns dashboard data only for the authenticated employee', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->actingAs($result->employee->user, 'sanctum')
        ->getJson('/api/employee/dashboard')
        ->assertOk()
        ->assertJsonPath('employee.id', $result->employee->id)
        ->assertJsonPath('summary.today_orders', 0)
        ->assertJsonPath('permissions.attendance', true);
});
