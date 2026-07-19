<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function deletionTestEmployeeData(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Krishna Rajbinde',
        'mobile' => '9158529605',
        'email' => 'krajibinde@gmail.com',
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

it('deletes linked user and tokens when an employee is removed', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(deletionTestEmployeeData([
        'aadhaar_number' => '234567890126',
        'pan_number' => 'ABCDE1234J',
    ]));

    $employee = $result->employee;
    $user = $employee->user;
    $user->createToken('test-device');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1);

    app(DeleteEmployeeWithUserAccount::class)->execute($employee);

    expect(Employee::query()->whereKey($employee->id)->exists())->toBeFalse()
        ->and(Employee::onlyTrashed()->whereKey($employee->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('allows recreating an employee with the same mobile and email after deletion', function () {
    $payload = deletionTestEmployeeData([
        'aadhaar_number' => '234567890127',
        'pan_number' => 'ABCDE1234K',
    ]);

    $first = app(CreateEmployeeWithUserAccount::class)->execute($payload);
    $firstUserId = $first->employee->user->id;

    app(DeleteEmployeeWithUserAccount::class)->execute($first->employee);

    $second = app(CreateEmployeeWithUserAccount::class)->execute([
        ...$payload,
        'aadhaar_number' => '234567890128',
        'pan_number' => 'ABCDE1234L',
    ]);

    expect($second->employee->mobile)->toBe('9158529605')
        ->and($second->employee->email)->toBe('krajibinde@gmail.com')
        ->and($second->employee->user->login_id)->toBe('9158529605')
        ->and($second->employee->user->email)->toBe('krajibinde@gmail.com')
        ->and($second->employee->user->id)->not->toBe($firstUserId)
        ->and(User::query()->where('employee_id', $second->employee->id)->count())->toBe(1)
        ->and(Hash::check('9605', $second->employee->user->password))->toBeTrue();
});

it('does not treat orphan user records as active duplicates', function () {
    User::query()->create([
        'employee_id' => null,
        'name' => 'Orphan Mangesh',
        'email' => 'mangesh.gavhane@paramgroup.in',
        'login_id' => '9145433004',
        'password' => Hash::make('3004'),
        'role' => 'employee',
    ]);

    $result = app(CreateEmployeeWithUserAccount::class)->execute(deletionTestEmployeeData([
        'full_name' => 'MANGESH GAVHANE',
        'mobile' => '9145433004',
        'email' => 'mangesh.gavhane@paramgroup.in',
        'aadhaar_number' => '234567890131',
        'pan_number' => 'ABCDE1234O',
    ]));

    expect($result->employee->mobile)->toBe('9145433004')
        ->and($result->employee->user->email)->toBe('mangesh.gavhane@paramgroup.in')
        ->and($result->employee->user->isLinkedToActiveEmployee())->toBeTrue()
        ->and(User::query()->where('login_id', '9145433004')->count())->toBe(1);
});

it('removes only the linked user account for the deleted employee', function () {
    $kept = app(CreateEmployeeWithUserAccount::class)->execute(deletionTestEmployeeData([
        'mobile' => '9876543213',
        'email' => 'kept@example.com',
        'aadhaar_number' => '234567890129',
        'pan_number' => 'ABCDE1234M',
    ]));

    $removed = app(CreateEmployeeWithUserAccount::class)->execute(deletionTestEmployeeData([
        'mobile' => '9876543214',
        'email' => 'removed@example.com',
        'aadhaar_number' => '234567890130',
        'pan_number' => 'ABCDE1234N',
    ]));

    app(DeleteEmployeeWithUserAccount::class)->execute($removed->employee);

    expect(User::query()->whereKey($kept->employee->user->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($removed->employee->user->id)->exists())->toBeFalse();
});
