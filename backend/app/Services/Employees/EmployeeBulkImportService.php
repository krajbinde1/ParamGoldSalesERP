<?php

namespace App\Services\Employees;

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\ProvisionEmployeeUserAccount;
use App\Actions\Employees\UpdateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Support\EmployeeCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class EmployeeBulkImportService
{
    public function __construct(
        private readonly EmployeeBulkImportReader $reader = new EmployeeBulkImportReader,
        private readonly EmployeeCodeResolver $employeeCodeResolver = new EmployeeCodeResolver,
    ) {}

    /**
     * @return list<array{
     *     row_number:int,
     *     data:array<string, mixed>,
     *     is_valid:bool,
     *     action:?string,
     *     error:?string
     * }>
     */
    public function preview(string $path): array
    {
        $rows = $this->reader->read($path);

        return array_map(function (array $row): array {
            $existing = $this->resolveExistingEmployee($row['data']);
            $validation = $this->validateRow($row['data'], $existing);

            return [
                'row_number' => $row['row_number'],
                'data' => $row['data'],
                'is_valid' => $validation === null,
                'action' => $validation === null ? ($existing === null ? 'create' : 'update') : null,
                'error' => $validation,
            ];
        }, $rows);
    }

    public function import(string $path): EmployeeBulkImportResult
    {
        $rows = $this->reader->read($path);
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $row) {
            $existing = $this->resolveExistingEmployee($row['data']);
            $validation = $this->validateRow($row['data'], $existing);

            if ($validation !== null) {
                $errors[] = new EmployeeBulkImportRowError(
                    rowNumber: $row['row_number'],
                    rowData: $row['data'],
                    reason: $validation,
                );

                continue;
            }

            try {
                $action = DB::transaction(fn (): string => $this->persistRow($row['data'], $existing));

                if ($action === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (ValidationException $exception) {
                $errors[] = new EmployeeBulkImportRowError(
                    rowNumber: $row['row_number'],
                    rowData: $row['data'],
                    reason: collect($exception->errors())->flatten()->first() ?? 'Unable to import this row.',
                );
            } catch (\Throwable $exception) {
                Log::error('Employee bulk import row failed.', [
                    'row_number' => $row['row_number'],
                    'mobile' => $row['data']['mobile'] ?? null,
                    'message' => $exception->getMessage(),
                ]);

                $errors[] = new EmployeeBulkImportRowError(
                    rowNumber: $row['row_number'],
                    rowData: $row['data'],
                    reason: $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Unable to import this row.',
                );
            }
        }

        return new EmployeeBulkImportResult(
            totalRows: count($rows),
            created: $created,
            updated: $updated,
            errors: $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateRow(array $data, ?Employee $existing = null): ?string
    {
        $missing = $this->missingMandatoryFields($data);

        if ($missing !== []) {
            return 'Missing mandatory field: '.implode(', ', $missing).'.';
        }

        $role = $this->parseRole($data['role'] ?? null);

        if ($role === null) {
            return 'Role must be Employee, Manager, Production Supervisor, or Director.';
        }

        $travelType = $this->parseTravelAllowanceType($data['travel_allowance_type'] ?? null);

        if ($travelType === null) {
            return 'Travel Allowance Type must be Actual Expense or Per KM.';
        }

        if ($travelType === 'per_km') {
            return 'Per KM travel allowance is not supported in bulk import. Use Actual Expense or create the employee manually.';
        }

        $joiningDate = $this->parseJoiningDate($data['joining_date'] ?? null);

        if ($joiningDate === null) {
            return 'Joining Date must be a valid date.';
        }

        $mobile = $this->normalizeMobile($data['mobile'] ?? null);

        if ($mobile === null) {
            return 'Mobile Number must be a valid 10-digit Indian mobile number.';
        }

        if ($this->parseStatus($data['status'] ?? null) === null) {
            return 'Status must be Active or Inactive.';
        }

        $companyCardIssued = $this->parseBoolean($data['company_card_issued'] ?? null);

        if ($companyCardIssued === null) {
            return 'Company Card Issued must be Yes or No.';
        }

        if (blank($data['monthly_travel_expense_limit'] ?? null)) {
            return 'Monthly Travel Expense Limit is required for Actual Expense travel allowance.';
        }

        if ($companyCardIssued && blank($data['company_card_last_four'] ?? null)) {
            return 'Company Card Last 4 Digits is required when Company Card Issued is Yes.';
        }

        $reportingManagerCode = trim((string) ($data['reporting_manager_employee_code'] ?? ''));

        if ($reportingManagerCode !== '') {
            $manager = $this->employeeCodeResolver->resolveActiveEmployee($reportingManagerCode);

            if ($manager === null) {
                return 'No active employee found for the reporting manager employee code.';
            }

            if ($existing !== null && (int) $manager->id === (int) $existing->id) {
                return 'An employee cannot be their own reporting manager.';
            }
        }

        $conflict = $this->resolveIdentifierConflict($data, $existing);

        if ($conflict !== null) {
            return $conflict;
        }

        $payload = $this->buildEmployeePayload($data, $existing);

        $validator = Validator::make($payload, Employee::creationRules($existing));
        Employee::validateTravelKmLimits($validator);
        Employee::validateCompanyCard($validator);
        Employee::validateReportingManager($validator, $existing);

        if ($validator->fails()) {
            return collect($validator->errors()->all())->first() ?? 'Invalid row data.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistRow(array $data, ?Employee $existing): string
    {
        $payload = $this->buildEmployeePayload($data, $existing);

        if ($existing !== null) {
            app(UpdateEmployeeWithUserAccount::class)->execute($existing, $payload);
            $employee = $existing->fresh(['user']);

            if ($employee->user === null) {
                app(ProvisionEmployeeUserAccount::class)->execute($employee, $payload['role']);
            }

            return 'updated';
        }

        app(CreateEmployeeWithUserAccount::class)->execute($payload);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildEmployeePayload(array $data, ?Employee $existing): array
    {
        $role = $this->parseRole($data['role'] ?? null);
        $travelType = $this->parseTravelAllowanceType($data['travel_allowance_type'] ?? null);
        $companyCardIssued = $this->parseBoolean($data['company_card_issued'] ?? null) ?? false;
        $reportingManagerId = null;
        $managerCode = trim((string) ($data['reporting_manager_employee_code'] ?? ''));

        if ($managerCode !== '') {
            $reportingManagerId = $this->employeeCodeResolver->resolveActiveEmployeeId($managerCode);
        }

        $payload = [
            'full_name' => trim((string) $data['full_name']),
            'mobile' => $this->normalizeMobile($data['mobile'] ?? null),
            'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
            'role' => $role,
            'department' => $existing?->department ?? 'Sales',
            'designation' => $existing?->designation ?? UserRole::from($role)->label(),
            'base_location' => $existing?->base_location ?? 'Not Specified',
            'reporting_manager_id' => $reportingManagerId,
            'joining_date' => $this->parseJoiningDate($data['joining_date'] ?? null),
            'salary' => $data['salary'],
            'daily_allowance' => $data['daily_allowance'],
            'travel_allowance_type' => $travelType,
            'company_card_issued' => $companyCardIssued,
            'monthly_travel_expense_limit' => $data['monthly_travel_expense_limit'],
            'company_card_last_four' => filled($data['company_card_last_four'] ?? null)
                ? $data['company_card_last_four']
                : null,
            'aadhaar_number' => $data['aadhaar_number'],
            'pan_number' => $data['pan_number'],
            'bank_name' => trim((string) $data['bank_name']),
            'account_number' => trim((string) $data['account_number']),
            'ifsc_code' => $data['ifsc_code'],
            'status' => $this->parseStatus($data['status'] ?? null) ?? true,
        ];

        return Employee::normalizeCreationData($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveExistingEmployee(array $data): ?Employee
    {
        $mobile = $this->normalizeMobile($data['mobile'] ?? null);

        if ($mobile === null) {
            return null;
        }

        $matches = collect();

        $mobileMatch = Employee::query()->where('mobile', $mobile)->first();

        if ($mobileMatch !== null) {
            $matches->push($mobileMatch);
        }

        if (filled($data['email'] ?? null)) {
            $emailMatch = Employee::query()->where('email', trim((string) $data['email']))->first();

            if ($emailMatch !== null) {
                $matches->push($emailMatch);
            }
        }

        if (filled($data['aadhaar_number'] ?? null)) {
            $aadhaar = preg_replace('/\D/', '', (string) $data['aadhaar_number']);
            $aadhaarMatch = Employee::query()->where('aadhaar_number', $aadhaar)->first();

            if ($aadhaarMatch !== null) {
                $matches->push($aadhaarMatch);
            }
        }

        if (filled($data['pan_number'] ?? null)) {
            $panMatch = Employee::query()->where('pan_number', strtoupper(trim((string) $data['pan_number'])))->first();

            if ($panMatch !== null) {
                $matches->push($panMatch);
            }
        }

        $unique = $matches->unique('id');

        return $unique->count() === 1 ? $unique->first() : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIdentifierConflict(array $data, ?Employee $existing): ?string
    {
        $mobile = $this->normalizeMobile($data['mobile'] ?? null);

        if ($mobile === null) {
            return null;
        }

        $matches = collect();

        $mobileMatch = Employee::query()->where('mobile', $mobile)->first();

        if ($mobileMatch !== null) {
            $matches->push($mobileMatch);
        }

        if (filled($data['email'] ?? null)) {
            $emailMatch = Employee::query()->where('email', trim((string) $data['email']))->first();

            if ($emailMatch !== null) {
                $matches->push($emailMatch);
            }
        }

        if (filled($data['aadhaar_number'] ?? null)) {
            $aadhaar = preg_replace('/\D/', '', (string) $data['aadhaar_number']);
            $aadhaarMatch = Employee::query()->where('aadhaar_number', $aadhaar)->first();

            if ($aadhaarMatch !== null) {
                $matches->push($aadhaarMatch);
            }
        }

        if (filled($data['pan_number'] ?? null)) {
            $panMatch = Employee::query()->where('pan_number', strtoupper(trim((string) $data['pan_number'])))->first();

            if ($panMatch !== null) {
                $matches->push($panMatch);
            }
        }

        $unique = $matches->unique('id');

        if ($unique->isEmpty()) {
            return null;
        }

        if ($unique->count() > 1) {
            return 'Mobile, Email, Aadhaar, and PAN refer to different existing employees.';
        }

        $matched = $unique->first();

        if ($existing !== null && (int) $matched->id !== (int) $existing->id) {
            return 'Mobile, Email, Aadhaar, and PAN refer to different existing employees.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function missingMandatoryFields(array $data): array
    {
        $missing = [];

        foreach (EmployeeBulkImportTemplate::MANDATORY_COLUMNS as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function parseRole(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        foreach (UserRole::cases() as $case) {
            if (strcasecmp($case->value, $raw) === 0 || strcasecmp($case->label(), $raw) === 0) {
                return $case->value;
            }
        }

        $normalized = Str::of($raw)->lower()->replace(' ', '_')->toString();

        return UserRole::tryFrom($normalized)?->value;
    }

    private function parseTravelAllowanceType(mixed $value): ?string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->trim()
            ->replace(' ', '_')
            ->toString();

        return match ($normalized) {
            'per_km', 'perkm', 'per-km' => 'per_km',
            'actual_expense', 'actualexpense', 'actual-expense', 'actual' => 'actual_expense',
            default => null,
        };
    }

    private function parseJoiningDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $timestamp = strtotime((string) $value);

        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function normalizeMobile(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 10 && preg_match('/^[6-9][0-9]{9}$/', $digits)) {
            return $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && preg_match('/^[6-9][0-9]{9}$/', $digits)) {
            return $digits;
        }

        return null;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        return null;
    }

    private function parseStatus(mixed $value): ?bool
    {
        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'active', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'inactive', 'no'], true)) {
            return false;
        }

        return null;
    }
}
