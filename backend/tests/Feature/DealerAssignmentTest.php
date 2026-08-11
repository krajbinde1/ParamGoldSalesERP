<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Actions\Employees\ReassignEmployeeDealers;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Models\Dealer;
use App\Services\Dealers\DealerAccessService;
use App\Services\Dealers\DealerBulkImportService;
use App\Services\Dealers\DealerBulkImportTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function dealerAssignmentEmployeeData(array $overrides = []): array
{
    static $counter = 9100000000;

    $counter++;

    return array_merge([
        'full_name' => 'Dealer Field Employee '.$counter,
        'mobile' => (string) $counter,
        'email' => "dealer.employee.{$counter}@example.com",
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-11',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '2'.str_pad((string) ($counter % 100000000000), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) $counter, 12, '2', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides);
}

function createAssignableEmployee(array $overrides = []): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)
        ->execute(dealerAssignmentEmployeeData($overrides))
        ->employee
        ->refresh();
}

function createAssignedDealer(\App\Models\Employee $employee, array $overrides = []): Dealer
{
    static $mobileCounter = 9800000000;

    $mobileCounter++;

    return Dealer::query()->create(array_merge([
        'firm_name' => 'Dealer '.$mobileCounter,
        'owner_name' => 'Owner '.$mobileCounter,
        'mobile' => (string) $mobileCounter,
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ], $overrides));
}

function dealerImportHeaderLine(): string
{
    return implode(',', DealerBulkImportTemplate::allColumns());
}

function dealerImportDataLine(array $overrides = []): string
{
    $values = [
        'firm_name' => 'ABC Agro',
        'assigned_employee_code' => 'E001',
        'mobile' => '9876543210',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'owner_name' => 'Rajesh Kumar',
        'dealer_type' => 'Retailer',
        'gst_no' => '',
        'pan_no' => '',
        'fertilizer_license_no' => '',
        'address' => 'Market Road',
        'pincode' => '412207',
        'credit_limit' => '0',
        'outstanding' => '0',
        'latitude' => '',
        'longitude' => '',
        'status' => '1',
        'email' => '',
    ];

    $values = array_merge($values, $overrides);

    return implode(',', array_map(
        fn (string $column): string => $values[$column] ?? '',
        DealerBulkImportTemplate::allColumns(),
    ));
}

it('imports dealers using assigned employee code and skips invalid rows', function () {
    $employee = createAssignableEmployee();
    $otherEmployee = createAssignableEmployee();

    $csv = implode("\n", [
        dealerImportHeaderLine(),
        dealerImportDataLine(['assigned_employee_code' => $employee->employee_code]),
        dealerImportDataLine([
            'mobile' => '9876543211',
            'assigned_employee_code' => '',
        ]),
        dealerImportDataLine([
            'mobile' => '9876543212',
            'assigned_employee_code' => 'EMP999999',
        ]),
        dealerImportDataLine([
            'mobile' => '9876543213',
            'firm_name' => 'Valid Dealer',
            'assigned_employee_code' => strtolower($otherEmployee->employee_code),
        ]),
    ]);

    $path = storage_path('framework/testing/dealer-import.csv');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $csv);

    $result = app(DealerBulkImportService::class)->import($path);

    expect($result->imported)->toBe(2)
        ->and($result->failed())->toBe(2)
        ->and(Dealer::query()->where('firm_name', 'ABC Agro')->value('assigned_employee_id'))->toBe($employee->id)
        ->and(Dealer::query()->where('firm_name', 'Valid Dealer')->value('assigned_employee_id'))->toBe($otherEmployee->id)
        ->and(Dealer::query()->where('mobile', '9876543211')->exists())->toBeFalse()
        ->and(Dealer::query()->where('mobile', '9876543212')->exists())->toBeFalse();
});

it('rejects rows with missing mandatory fields and imports valid rows', function () {
    $employee = createAssignableEmployee();

    $csv = implode("\n", [
        dealerImportHeaderLine(),
        dealerImportDataLine(['assigned_employee_code' => $employee->employee_code]),
        dealerImportDataLine([
            'mobile' => '9876543211',
            'village' => '',
            'assigned_employee_code' => $employee->employee_code,
        ]),
    ]);

    $path = storage_path('framework/testing/dealer-import-mandatory.csv');
    File::put($path, $csv);

    $result = app(DealerBulkImportService::class)->import($path);

    expect($result->imported)->toBe(1)
        ->and($result->failed())->toBe(1)
        ->and($result->errors[0]->reason)->toContain('Missing mandatory field: village');
});

it('imports rows when only mandatory columns are filled and auto-generates short dealer code', function () {
    $employee = createAssignableEmployee();

    $mandatoryOnlyHeader = implode(',', DealerBulkImportTemplate::MANDATORY_COLUMNS);
    $mandatoryOnlyRow = implode(',', [
        'ABC Agro',
        $employee->employee_code,
        '9876543210',
        'Maharashtra',
        'Pune',
        'Haveli',
        'Wagholi',
    ]);

    $csv = implode("\n", [$mandatoryOnlyHeader, $mandatoryOnlyRow]);

    $path = storage_path('framework/testing/dealer-import-mandatory-only.csv');
    File::put($path, $csv);

    app(DealerBulkImportService::class)->import($path);

    $dealer = Dealer::query()->where('firm_name', 'ABC Agro')->first();

    expect($dealer)->not->toBeNull()
        ->and($dealer->dealer_code)->toMatch('/^D\d+$/')
        ->and($dealer->dealer_code)->toBe('D001')
        ->and($dealer->owner_name)->toBeNull()
        ->and($dealer->address)->toBeNull()
        ->and($dealer->email)->toBeNull()
        ->and($dealer->gst_no)->toBeNull()
        ->and($dealer->assigned_employee_id)->toBe($employee->id);
});

it('does not change existing DLR dealer codes and continues D-series from the highest D code', function () {
    $employee = createAssignableEmployee();

    Dealer::query()->create([
        'dealer_code' => 'DLR000001',
        'firm_name' => 'Legacy Dealer',
        'mobile' => '9876500001',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    Dealer::query()->create([
        'dealer_code' => 'D005',
        'firm_name' => 'Short Code Dealer',
        'mobile' => '9876500002',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $newDealer = createAssignedDealer($employee, [
        'firm_name' => 'New Format Dealer',
        'mobile' => '9876500003',
    ]);

    expect(Dealer::query()->where('firm_name', 'Legacy Dealer')->value('dealer_code'))->toBe('DLR000001')
        ->and($newDealer->dealer_code)->toBe('D006');
});

it('imports files exported from the marked template format', function () {
    $employee = createAssignableEmployee();
    $template = DealerBulkImportTemplate::csv();
    $dataLine = dealerImportDataLine(['assigned_employee_code' => $employee->employee_code]);
    $csv = $template.$dataLine;

    $path = storage_path('framework/testing/dealer-import-template.csv');
    File::put($path, $csv);

    $result = app(DealerBulkImportService::class)->import($path);

    expect($result->imported)->toBe(1)
        ->and($result->failed())->toBe(0);
});

it('rejects bulk import files that still use assigned employee mobile column', function () {
    $headers = str_replace('assigned_employee_code', 'assigned_employee_mobile', dealerImportHeaderLine());
    $csv = $headers."\n".dealerImportDataLine(['assigned_employee_code' => 'E001']);
    $path = storage_path('framework/testing/dealer-import-mobile.csv');
    File::put($path, $csv);

    app(DealerBulkImportService::class)->import($path);
})->throws(ValidationException::class);

it('ignores whatsapp and alternate mobile columns if present in older import files', function () {
    $employee = createAssignableEmployee();

    $headers = dealerImportHeaderLine().',alt_mobile,whatsapp';
    $csv = $headers."\n".dealerImportDataLine(['assigned_employee_code' => $employee->employee_code]).',9876500099,9876500098';
    $path = storage_path('framework/testing/dealer-import-alt-mobile.csv');
    File::put($path, $csv);

    $result = app(DealerBulkImportService::class)->import($path);

    $dealer = Dealer::query()->where('firm_name', 'ABC Agro')->first();

    expect($result->imported)->toBe(1)
        ->and($dealer)->not->toBeNull()
        ->and($dealer->alternate_mobile)->toBeNull()
        ->and($dealer->whatsapp)->toBeNull();
});

it('scopes dealer access to the assigned employee on mobile api', function () {
    $employeeA = createAssignableEmployee();
    $employeeB = createAssignableEmployee();

    createAssignedDealer($employeeA, ['firm_name' => 'A Dealer']);
    createAssignedDealer($employeeB, ['firm_name' => 'B Dealer']);

    $this->actingAs($employeeA->user, 'sanctum')
        ->getJson('/api/employee/dealers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.firm_name', 'A Dealer');
});

it('reassigns all dealers from one employee to another', function () {
    $fromEmployee = createAssignableEmployee();
    $toEmployee = createAssignableEmployee();

    createAssignedDealer($fromEmployee, ['firm_name' => 'First Dealer']);
    createAssignedDealer($fromEmployee, ['firm_name' => 'Second Dealer']);

    $reassigned = app(ReassignEmployeeDealers::class)->execute($fromEmployee, $toEmployee);

    expect($reassigned)->toBe(2)
        ->and(Dealer::query()->where('assigned_employee_id', $fromEmployee->id)->count())->toBe(0)
        ->and(Dealer::query()->where('assigned_employee_id', $toEmployee->id)->count())->toBe(2);
});

it('blocks deleting an employee while dealers remain assigned', function () {
    $employee = createAssignableEmployee();
    createAssignedDealer($employee);

    app(DeleteEmployeeWithUserAccount::class)->execute($employee);
})->throws(ValidationException::class);

it('blocks deactivating an employee while dealers remain assigned', function () {
    $employee = createAssignableEmployee();
    createAssignedDealer($employee);

    app(UpdateEmployeeWithUserAccount::class)->execute($employee, [
        'full_name' => $employee->full_name,
        'mobile' => $employee->mobile,
        'email' => $employee->email,
        'department' => $employee->department,
        'designation' => $employee->designation,
        'joining_date' => $employee->joining_date->toDateString(),
        'salary' => $employee->salary,
        'base_location' => $employee->base_location,
        'daily_allowance' => $employee->daily_allowance,
        'travel_allowance_type' => $employee->travel_allowance_type,
        'company_card_issued' => $employee->company_card_issued,
        'monthly_travel_expense_limit' => $employee->monthly_travel_expense_limit,
        'aadhaar_number' => $employee->aadhaar_number,
        'pan_number' => $employee->pan_number,
        'bank_name' => $employee->bank_name,
        'account_number' => $employee->account_number,
        'ifsc_code' => $employee->ifsc_code,
        'status' => false,
    ]);
})->throws(ValidationException::class);

it('lets managers see dealers assigned to direct reports only', function () {
    $manager = createAssignableEmployee([
        'full_name' => 'Sales Manager',
        'mobile' => '9111111111',
        'email' => 'manager@example.com',
        'aadhaar_number' => '911111111111',
        'pan_number' => 'ABCDE1111F',
    ]);

    $managerUser = $manager->user;
    $managerUser->forceFill(['role' => 'manager'])->save();

    $report = createAssignableEmployee([
        'reporting_manager_id' => $manager->id,
        'mobile' => '9222222222',
        'email' => 'report@example.com',
        'aadhaar_number' => '922222222222',
        'pan_number' => 'ABCDE2222F',
    ]);

    $otherEmployee = createAssignableEmployee([
        'mobile' => '9333333333',
        'email' => 'other@example.com',
        'aadhaar_number' => '933333333333',
        'pan_number' => 'ABCDE3333F',
    ]);

    createAssignedDealer($report, ['firm_name' => 'Team Dealer']);
    createAssignedDealer($otherEmployee, ['firm_name' => 'Other Dealer']);

    $access = app(DealerAccessService::class);

    expect($access->canAccessDealer($managerUser, Dealer::query()->where('firm_name', 'Team Dealer')->first()))->toBeTrue()
        ->and($access->canAccessDealer($managerUser, Dealer::query()->where('firm_name', 'Other Dealer')->first()))->toBeFalse();
});
