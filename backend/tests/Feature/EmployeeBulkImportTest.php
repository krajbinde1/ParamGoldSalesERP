<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Exports\EmployeeImportErrorReportExport;
use App\Exports\EmployeeImportTemplateExport;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeBulkImportService;
use App\Services\Employees\EmployeeBulkImportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function employeeImportHeaderLine(): string
{
    return implode(',', EmployeeBulkImportTemplate::allColumns());
}

function employeeImportDataLine(array $overrides = []): string
{
    static $counter = 9600000000;

    $counter++;

    $values = [
        'full_name' => 'Bulk Import Employee',
        'mobile' => (string) $counter,
        'email' => "bulk.employee.{$counter}@example.com",
        'role' => 'Employee',
        'reporting_manager_employee_code' => '',
        'joining_date' => '2026-07-11',
        'salary' => '25000',
        'daily_allowance' => '300',
        'travel_allowance_type' => 'Actual Expense',
        'monthly_travel_expense_limit' => '500',
        'company_card_issued' => 'No',
        'company_card_last_four' => '',
        'aadhaar_number' => '2'.str_pad((string) ($counter % 100000000000), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789012',
        'ifsc_code' => 'TEST0123456',
        'status' => 'Active',
    ];

    $values = array_merge($values, $overrides);

    return implode(',', array_map(
        fn (string $column): string => $values[$column],
        EmployeeBulkImportTemplate::allColumns(),
    ));
}

function seedEmployeeImportManager(): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Import Manager',
        'mobile' => '9876500001',
        'email' => 'import.manager@example.com',
        'department' => 'Sales',
        'designation' => 'Manager',
        'joining_date' => '2026-07-01',
        'salary' => 30000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '234567890141',
        'pan_number' => 'ABCDE1234M',
        'bank_name' => 'Test Bank',
        'account_number' => '123456789141',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => 'manager',
    ])->employee->refresh();
}

it('generates an employee import template with mandatory and optional markers', function () {
    $export = new EmployeeImportTemplateExport;
    $rows = $export->array();

    expect($rows[0][1])->toBe('MANDATORY')
        ->and($rows[0][3])->toBe('OPTIONAL')
        ->and($rows[1])->toContain('Employee Name *')
        ->and($rows[1])->toContain('Mobile Number *')
        ->and($rows[1])->not->toContain('Employee Code');
});

it('imports valid employee rows from csv', function () {
    $csv = implode("\n", [
        employeeImportHeaderLine(),
        employeeImportDataLine(['full_name' => 'Imported Employee']),
    ]);

    $path = storage_path('framework/testing/employee-import-valid.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and($result->updated)->toBe(0)
        ->and($result->failed())->toBe(0)
        ->and(Employee::query()->where('full_name', 'Imported Employee')->exists())->toBeTrue();

    $employee = Employee::query()->where('full_name', 'Imported Employee')->first();

    expect($employee->employee_code)->toStartWith('E')
        ->and($employee->user?->login_id)->toBe($employee->mobile)
        ->and(Hash::check(substr($employee->mobile, -4), $employee->user->password))->toBeTrue();
});

it('skips invalid rows and imports valid rows', function () {
    $csv = implode("\n", [
        employeeImportHeaderLine(),
        employeeImportDataLine(),
        employeeImportDataLine(['full_name' => '', 'mobile' => '9876501111']),
        employeeImportDataLine([
            'mobile' => '9876501112',
            'travel_allowance_type' => 'Per KM',
        ]),
    ]);

    $path = storage_path('framework/testing/employee-import-invalid.csv');
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and($result->failed())->toBe(2)
        ->and(Employee::query()->count())->toBe(1);
});

it('updates an existing employee when mobile already exists', function () {
    $line = employeeImportDataLine([
        'mobile' => '9876502222',
        'email' => 'existing@example.com',
        'full_name' => 'Original Name',
    ]);

    $path = storage_path('framework/testing/employee-import-update.csv');
    File::put($path, employeeImportHeaderLine()."\n".$line);

    app(EmployeeBulkImportService::class)->import($path);

    File::put($path, employeeImportHeaderLine()."\n".employeeImportDataLine([
        'mobile' => '9876502222',
        'email' => 'existing@example.com',
        'full_name' => 'Updated Name',
        'salary' => '28000',
    ]));

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(1)
        ->and(Employee::query()->count())->toBe(1)
        ->and(Employee::query()->value('full_name'))->toBe('Updated Name')
        ->and(User::query()->count())->toBe(1);
});

it('maps reporting manager using employee code', function () {
    $manager = seedEmployeeImportManager();

    $csv = employeeImportHeaderLine()."\n".employeeImportDataLine([
        'reporting_manager_employee_code' => $manager->employee_code,
    ]);

    $path = storage_path('framework/testing/employee-import-manager.csv');
    File::put($path, $csv);

    app(EmployeeBulkImportService::class)->import($path);

    $employee = Employee::query()->where('full_name', 'Bulk Import Employee')->first();

    expect($employee->reporting_manager_id)->toBe($manager->id);
});

it('requires company card last four digits when company card is issued', function () {
    $csv = employeeImportHeaderLine()."\n".employeeImportDataLine([
        'company_card_issued' => 'Yes',
        'company_card_last_four' => '',
    ]);

    $path = storage_path('framework/testing/employee-import-card.csv');
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->failed())->toBe(1)
        ->and($result->errors[0]->reason)->toContain('Company Card Last 4 Digits');
});

it('allows zero daily allowance', function () {
    $csv = employeeImportHeaderLine()."\n".employeeImportDataLine([
        'daily_allowance' => '0',
    ]);

    $path = storage_path('framework/testing/employee-import-zero-da.csv');
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and(Employee::query()->value('daily_allowance'))->toBe('0.00');
});

it('stores pan in uppercase and preserves account number leading zeros', function () {
    $csv = employeeImportHeaderLine()."\n".employeeImportDataLine([
        'pan_number' => 'abcde1234z',
        'account_number' => '001234567890',
    ]);

    $path = storage_path('framework/testing/employee-import-identity.csv');
    File::put($path, $csv);

    app(EmployeeBulkImportService::class)->import($path);

    $employee = Employee::query()->latest('id')->first();

    expect($employee->pan_number)->toBe('ABCDE1234Z')
        ->and($employee->account_number)->toBe('001234567890');
});

it('builds an excel error report for failed rows', function () {
    $csv = employeeImportHeaderLine()."\n".employeeImportDataLine([
        'role' => 'Invalid Role',
    ]);

    $path = storage_path('framework/testing/employee-import-error-report.csv');
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);
    $export = new EmployeeImportErrorReportExport($result->errors);

    expect($result->failed())->toBe(1)
        ->and($export->headings())->toContain('Error')
        ->and($export->array()[0][7])->toContain('Role must be');
});

it('previews uploaded rows before import', function () {
    $csv = employeeImportHeaderLine()."\n".implode("\n", [
        employeeImportDataLine(),
        employeeImportDataLine(['full_name' => '', 'mobile' => '9876503333']),
    ]);

    $path = storage_path('framework/testing/employee-import-preview.csv');
    File::put($path, $csv);

    $preview = app(EmployeeBulkImportService::class)->preview($path);

    expect($preview)->toHaveCount(2)
        ->and($preview[0]['is_valid'])->toBeTrue()
        ->and($preview[1]['is_valid'])->toBeFalse();
});

it('imports files exported from the marked template format', function () {
    $templateRows = (new EmployeeImportTemplateExport)->array();
    $handle = fopen('php://temp', 'r+');

    foreach ($templateRows as $row) {
        fputcsv($handle, $row);
    }

    fputcsv($handle, explode(',', employeeImportDataLine([
        'full_name' => 'Template Employee',
    ])));

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $path = storage_path('framework/testing/employee-import-template.csv');
    File::put($path, $csv);

    $result = app(EmployeeBulkImportService::class)->import($path);

    expect($result->created)->toBe(1)
        ->and(Employee::query()->where('full_name', 'Template Employee')->exists())->toBeTrue();
});
