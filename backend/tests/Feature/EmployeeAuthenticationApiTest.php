<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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
    ])->assertForbidden()
        ->assertJsonPath('message', 'This employee account is inactive.');
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
        ])->assertUnprocessable()
        ->assertJsonPath('message', 'The current password is incorrect.');

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

it('returns inactive on me only when the linked employee is inactive', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());
    $user = $result->employee->user;
    $token = $user->createToken('employee-mobile')->plainTextToken;

    $this->withToken($token)->getJson('/api/me')->assertOk();

    $result->employee->update(['status' => false]);

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertForbidden()
        ->assertJsonPath('message', 'This employee account is inactive.');
});

it('completes temp-password login, mandatory password change, and me without inactive 403', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication([
        'mobile' => '9604326999',
        'email' => 'ganesh.auth.check@example.com',
        'aadhaar_number' => '234567890321',
        'pan_number' => 'ABCDE4321F',
        'account_number' => '123456789021',
    ]));
    $loginId = $result->employee->user->login_id;

    $login = $this->postJson('/api/login', [
        'login_id' => $loginId,
        'password' => '6999',
        'device_id' => 'device-auth-check',
    ])->assertOk();

    expect($login->json('user.must_change_password'))->toBeTrue()
        ->and($login->status())->not->toBe(403);

    $token = $login->json('token');

    $this->withToken($token)
        ->withHeader('X-Device-Id', 'device-auth-check')
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true);

    $changed = $this->withToken($token)
        ->withHeader('X-Device-Id', 'device-auth-check')
        ->postJson('/api/change-password', [
            'current_password' => '6999',
            'password' => 'NewSecure@123',
            'password_confirmation' => 'NewSecure@123',
        ]);

    $changed->assertOk()->assertJsonPath('user.must_change_password', false);
    expect($changed->status())->not->toBe(403)
        ->and($changed->json('message'))->not->toBe('This employee account is inactive.');

    $this->withToken($token)
        ->withHeader('X-Device-Id', 'device-auth-check')
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.must_change_password', false)
        ->assertJsonPath('employee.active', true);
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

it('allows every mobile role to update their own profile photo', function (string $role, string $mobile) {
    Storage::fake('public');

    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication([
        'role' => $role,
        'mobile' => $mobile,
        'email' => "photo.{$role}@example.com",
        'full_name' => "Photo {$role}",
    ]));

    $response = $this->actingAs($result->employee->user, 'sanctum')
        ->post('/api/profile-photo', [
            'photo' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json']);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('employee.id', $result->employee->id);

    $employee = $result->employee->fresh();
    expect($employee->profile_photo_path)->not->toBeNull()
        ->and($response->json('employee.profile_photo_url'))->toContain('v=');

    Storage::disk('public')->assertExists($employee->profile_photo_path);
})->with([
    [UserRole::Employee->value, '9145433010'],
    [UserRole::Manager->value, '9145433011'],
    [UserRole::ProductionSupervisor->value, '9145433012'],
    [UserRole::Director->value, '9145433013'],
]);

it('replaces the previous profile photo when a new one is uploaded', function () {
    Storage::fake('public');
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());
    $oldPath = UploadedFile::fake()->image('old.jpg')->store('employees/profile-photos', 'public');
    $result->employee->update(['profile_photo_path' => $oldPath]);

    $this->actingAs($result->employee->user, 'sanctum')
        ->post('/api/profile-photo', [
            'photo' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($result->employee->fresh()->profile_photo_path);
});

it('rejects unauthenticated profile photo uploads', function () {
    $this->post('/api/profile-photo', [
        'photo' => UploadedFile::fake()->image('avatar.jpg'),
    ], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('rejects profile photo uploads without a file', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForAuthentication());

    $this->actingAs($result->employee->user, 'sanctum')
        ->postJson('/api/profile-photo', [])
        ->assertUnprocessable();
});
