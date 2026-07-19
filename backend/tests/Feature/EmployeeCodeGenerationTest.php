<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Models\Employee;
use App\Support\EmployeeCodeGenerator;
use App\Support\EmployeeCodeResolver;

it('generates the first employee code as E001', function () {
    expect(EmployeeCodeGenerator::format(1))->toBe('E001');
});

it('assigns sequential employee codes on create', function () {
    $first = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'aadhaar_number' => '234567890141',
        'pan_number' => 'ABCDE1241F',
    ]));
    $second = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543299',
        'email' => 'second-code@example.com',
        'aadhaar_number' => '234567890142',
        'pan_number' => 'ABCDE1242F',
    ]));

    expect($first->employee->employee_code)->toBe('E001')
        ->and($second->employee->employee_code)->toBe('E002');
});

it('ignores a submitted employee code on create', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'employee_code' => 'E999',
        'aadhaar_number' => '234567890143',
        'pan_number' => 'ABCDE1243F',
    ]));

    expect($result->employee->employee_code)->toBe('E001');
});

it('never changes an employee code after creation', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'aadhaar_number' => '234567890144',
        'pan_number' => 'ABCDE1244F',
    ]));
    $originalCode = $result->employee->employee_code;

    app(UpdateEmployeeWithUserAccount::class)->execute($result->employee, validEmployeeData([
        'full_name' => 'Updated Name',
        'employee_code' => 'E999',
    ]));

    expect($result->employee->fresh()->employee_code)->toBe($originalCode);
});

it('does not reuse employee codes after deletion', function () {
    $first = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'aadhaar_number' => '234567890145',
        'pan_number' => 'ABCDE1245F',
    ]));

    app(DeleteEmployeeWithUserAccount::class)->execute($first->employee);

    $second = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543298',
        'email' => 'recreated-code@example.com',
        'aadhaar_number' => '234567890146',
        'pan_number' => 'ABCDE1246F',
    ]));

    expect($first->employee->fresh()->employee_code)->toBe('E001')
        ->and($second->employee->employee_code)->toBe('E002');
});

it('resolves active employees by code for bulk import mapping', function () {
    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'aadhaar_number' => '234567890147',
        'pan_number' => 'ABCDE1247F',
    ]));
    $resolver = app(EmployeeCodeResolver::class);

    expect($resolver->resolveActiveEmployeeId('E001'))->toBe($result->employee->id)
        ->and($resolver->resolveActiveEmployee('E001')?->full_name)->toBe('Asha Patil')
        ->and($resolver->resolveActiveEmployeeId('E999'))->toBeNull();
});

it('continues numbering after legacy EMP codes', function () {
    Employee::withoutEvents(function (): void {
        $legacy = new Employee([
            'full_name' => 'Legacy Employee',
            'mobile' => '9876543297',
            'email' => 'legacy@example.com',
            'department' => 'Sales',
            'designation' => 'Sales Executive',
            'joining_date' => '2026-07-11',
            'salary' => 25000,
            'base_location' => 'Pune',
            'daily_allowance' => 300,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 500,
            'aadhaar_number' => '234567890148',
            'pan_number' => 'ABCDE1248F',
            'bank_name' => 'Test Bank',
            'account_number' => '123456789013',
            'ifsc_code' => 'TEST0123456',
            'status' => true,
        ]);
        $legacy->employee_code = 'EMP000025';
        $legacy->save();
    });

    $result = app(CreateEmployeeWithUserAccount::class)->execute(validEmployeeData([
        'mobile' => '9876543296',
        'email' => 'after-legacy@example.com',
        'aadhaar_number' => '234567890149',
        'pan_number' => 'ABCDE1249F',
    ]));

    expect($result->employee->employee_code)->toBe('E026');
});
