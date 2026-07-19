<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

function validEmployeeData(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Asha Patil',
        'mobile' => '9876543210',
        'email' => 'asha@example.com',
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-11',
        'salary' => 25000,
        'base_location' => 'Pune',
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

it('creates an employee and linked user in one action', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());
    $user = $result->employee->user;

    expect($result->loginId)->toBe($result->employee->mobile)
        ->and($user)->toBeInstanceOf(User::class)
        ->and($user->employee_id)->toBe($result->employee->id)
        ->and($user->login_id)->toBe($result->employee->mobile)
        ->and($user->role)->toBe('employee')
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check($result->temporaryPassword, $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($result->temporaryPassword)
        ->and($user->employee->is($result->employee))->toBeTrue();
});

it('does not create another user when employee contact details change', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, validEmployeeData([
        'mobile' => '9876543211',
        'email' => 'asha.updated@example.com',
    ]));

    expect(User::query()->where('employee_id', $result->employee->id)->count())->toBe(1);
});

it('prevents duplicate user accounts for the same employee', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    expect(fn () => User::query()->create([
        'employee_id' => $result->employee->id,
        'name' => 'Duplicate',
        'email' => 'duplicate@example.com',
        'login_id' => 'DUPLICATE',
        'password' => Hash::make('irrelevant'),
        'role' => 'employee',
    ]))->toThrow(QueryException::class);
});

it('rolls employee creation back when user creation fails', function () {
    User::factory()->create([
        'email' => 'asha@example.com',
        'login_id' => '9876543210',
    ]);

    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    expect(Employee::query()->count())->toBe(1)
        ->and(User::query()->where('login_id', '9876543210')->count())->toBe(1)
        ->and($result->employee->user->isLinkedToActiveEmployee())->toBeTrue();
});

it('uses the last four mobile digits as a hashed initial password', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9158529605',
    ]));

    expect($result->loginId)->toBe('9158529605')
        ->and($result->temporaryPassword)->toBe('9605')
        ->and(Hash::check('9605', $result->employee->user->password))->toBeTrue()
        ->and($result->employee->user->password)->not->toBe('9605');
});

it('rejects a duplicate employee mobile or user login id', function () {
    app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'email' => 'other@example.com',
        'aadhaar_number' => '234567890124',
        'pan_number' => 'ABCDE1234G',
    ])))->toThrow(ValidationException::class);
});

it('accepts a valid ten digit Indian mobile number', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9145433002',
    ]));

    expect($result->employee->mobile)->toBe('9145433002')
        ->and($result->employee->user->login_id)->toBe('9145433002');
});

it('rejects a mobile number with fewer than ten digits', function () {
    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '914543300',
    ])))->toThrow(ValidationException::class);
});

it('rejects a mobile number with more than ten digits', function () {
    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '91454330022',
    ])))->toThrow(ValidationException::class);
});

it('rejects letters in a mobile number', function () {
    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '91454A3002',
    ])))->toThrow(ValidationException::class);
});

it('ignores a submitted login id on create and uses mobile instead', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'login_id' => 'customlogin99',
    ]));

    expect($result->employee->user->login_id)->toBe('9876543210');
});

it('ignores a submitted login id on update and syncs login id to mobile', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, validEmployeeData([
        'mobile' => '9876543211',
        'login_id' => 'shouldbeignored',
    ]));

    expect($result->employee->fresh()->user->login_id)->toBe('9876543211');
});

it('updates login id without resetting password when mobile changes', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());
    $passwordHash = $result->employee->user->password;

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, validEmployeeData([
        'mobile' => '9876543211',
    ]));

    $user = $result->employee->user->fresh();
    expect($user->login_id)->toBe('9876543211')
        ->and($user->password)->toBe($passwordHash)
        ->and(User::query()->where('employee_id', $result->employee->id)->count())->toBe(1);
});

it('removes whatsapp from employee mass assignment', function () {
    expect((new Employee)->getFillable())->not->toContain('whatsapp');
});

it('validates and stores a per km travel policy', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'travel_allowance_type' => 'per_km',
        'rate_per_km' => 8.5,
        'daily_km_limit' => 100,
        'monthly_km_limit' => 2000,
        'company_card_issued' => true,
        'monthly_travel_expense_limit' => 9000,
        'company_card_last_four' => '1234',
    ]));

    expect($result->employee->rate_per_km)->toBe('8.50')
        ->and($result->employee->monthly_travel_expense_limit)->toBeNull()
        ->and($result->employee->company_card_issued)->toBeFalse()
        ->and($result->employee->company_card_last_four)->toBeNull();

    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543212',
        'email' => 'perkm-invalid@example.com',
        'aadhaar_number' => '234567890125',
        'pan_number' => 'ABCDE1234H',
        'travel_allowance_type' => 'per_km',
        'rate_per_km' => 8,
        'daily_km_limit' => 200,
        'monthly_km_limit' => 100,
    ])))->toThrow(ValidationException::class);
});

it('validates and stores an actual expense travel policy', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => true,
        'monthly_travel_expense_limit' => 12000,
        'company_card_last_four' => '4321',
        'rate_per_km' => 9,
        'daily_km_limit' => 100,
        'monthly_km_limit' => 2000,
    ]));

    expect($result->employee->monthly_travel_expense_limit)->toBe('12000.00')
        ->and($result->employee->company_card_last_four)->toBe('4321')
        ->and($result->employee->rate_per_km)->toBeNull()
        ->and($result->employee->daily_km_limit)->toBeNull()
        ->and($result->employee->monthly_km_limit)->toBeNull();
});

it('requires exactly four numeric card digits when a company card is issued', function () {
    expect(fn () => app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'company_card_issued' => true,
        'company_card_last_four' => '12A4',
    ])))->toThrow(ValidationException::class);
});

it('blocks mobile login for an inactive employee', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());
    $result->employee->update(['status' => false]);

    expect($result->employee->user->fresh()->canLoginToMobile())->toBeFalse();
});

it('allows mobile login only for an active linked employee account', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData());

    expect($result->employee->user->canLoginToMobile())->toBeTrue()
        ->and(User::factory()->create(['role' => 'employee'])->canLoginToMobile())->toBeFalse();
});

it('creates an employee when company card is not issued without card digit validation', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'company_card_last_four' => null,
        'monthly_travel_expense_limit' => 500,
    ]));

    expect($result->employee->company_card_last_four)->toBeNull()
        ->and($result->employee->user)->not->toBeNull();
});

it('preserves leading zeros in company card last four digits', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543212',
        'email' => 'card-zero@example.com',
        'aadhaar_number' => '234567890125',
        'pan_number' => 'ABCDE1234H',
        'company_card_issued' => true,
        'company_card_last_four' => '0123',
    ]));

    expect($result->employee->company_card_last_four)->toBe('0123');
});

it('stores bank account numbers as strings with leading zeros', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543213',
        'email' => 'leading-zero@example.com',
        'aadhaar_number' => '234567890126',
        'pan_number' => 'ABCDE1234I',
        'account_number' => '001234567890',
    ]));

    expect($result->employee->account_number)->toBe('001234567890');
});
