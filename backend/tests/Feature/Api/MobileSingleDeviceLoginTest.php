<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Services\Auth\MobileSessionService;
use Illuminate\Support\Facades\Hash;

function employeeForSingleDeviceLogin(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Single Device Employee',
        'mobile' => '9145433099',
        'email' => 'single.device@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-11',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '234567890199',
        'pan_number' => 'ABCDE1299F',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789099',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides);
}

it('invalidates device A when the same user logs in on device B', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForSingleDeviceLogin());
    $user = $result->employee->user;
    $user->forceFill([
        'password' => Hash::make('Secret123!'),
        'must_change_password' => false,
    ])->save();

    $deviceA = $this->postJson('/api/login', [
        'login_id' => $user->login_id,
        'password' => 'Secret123!',
        'device_id' => 'device-a-uuid',
    ])->assertOk();

    $tokenA = $deviceA->json('token');
    expect($tokenA)->not->toBeEmpty();

    $deviceB = $this->postJson('/api/login', [
        'login_id' => $user->login_id,
        'password' => 'Secret123!',
        'device_id' => 'device-b-uuid',
    ])->assertOk();

    $tokenB = $deviceB->json('token');
    expect($tokenB)->not->toBeEmpty()->not->toBe($tokenA);

    $this->withToken($tokenA)
        ->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', MobileSessionService::CODE_SESSION_REPLACED)
        ->assertJsonPath('message', MobileSessionService::MESSAGE_SESSION_REPLACED);

    $this->withToken($tokenB)
        ->withHeader('X-Device-Id', 'device-b-uuid')
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('success', true);

    $deviceAAgain = $this->postJson('/api/login', [
        'login_id' => $user->login_id,
        'password' => 'Secret123!',
        'device_id' => 'device-a-uuid',
    ])->assertOk();

    $tokenA2 = $deviceAAgain->json('token');
    expect($tokenA2)->not->toBe($tokenB);

    // HTTP tests reuse the app singleton; clear cached guard user between requests.
    $this->app['auth']->forgetGuards();

    $this->withToken($tokenB)
        ->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', MobileSessionService::CODE_SESSION_REPLACED);

    $this->app['auth']->forgetGuards();

    $this->withToken($tokenA2)
        ->withHeader('X-Device-Id', 'device-a-uuid')
        ->getJson('/api/me')
        ->assertOk();
});

it('clears the active mobile session on logout', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(employeeForSingleDeviceLogin([
        'mobile' => '9145433098',
        'email' => 'single.device.logout@example.com',
        'aadhaar_number' => '234567890198',
        'pan_number' => 'ABCDE1298F',
        'account_number' => '123456789098',
    ]));
    $user = $result->employee->user;
    $user->forceFill([
        'password' => Hash::make('Secret123!'),
        'must_change_password' => false,
    ])->save();

    $token = $this->postJson('/api/login', [
        'login_id' => $user->login_id,
        'password' => 'Secret123!',
        'device_id' => 'device-logout',
    ])->assertOk()->json('token');

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    $user->refresh();
    expect($user->active_mobile_session_id)->toBeNull()
        ->and($user->active_mobile_token_id)->toBeNull()
        ->and($user->tokens()->where('name', MobileSessionService::TOKEN_NAME)->exists())->toBeFalse();
});
