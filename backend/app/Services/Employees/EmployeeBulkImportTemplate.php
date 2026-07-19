<?php

namespace App\Services\Employees;

final class EmployeeBulkImportTemplate
{
    /** @var list<string> */
    public const ALL_COLUMNS = [
        'full_name',
        'mobile',
        'email',
        'role',
        'reporting_manager_employee_code',
        'joining_date',
        'salary',
        'daily_allowance',
        'travel_allowance_type',
        'monthly_travel_expense_limit',
        'company_card_issued',
        'company_card_last_four',
        'aadhaar_number',
        'pan_number',
        'bank_name',
        'account_number',
        'ifsc_code',
        'status',
    ];

    /** @var list<string> */
    public const MANDATORY_COLUMNS = [
        'full_name',
        'mobile',
        'role',
        'joining_date',
        'salary',
        'daily_allowance',
        'travel_allowance_type',
        'company_card_issued',
        'aadhaar_number',
        'pan_number',
        'bank_name',
        'account_number',
        'ifsc_code',
        'status',
    ];

    /** @var list<string> */
    public const OPTIONAL_COLUMNS = [
        'email',
        'reporting_manager_employee_code',
        'monthly_travel_expense_limit',
        'company_card_last_four',
    ];

    /** @var array<string, string> */
    public const COLUMN_LABELS = [
        'full_name' => 'Employee Name *',
        'mobile' => 'Mobile Number *',
        'email' => 'Email',
        'role' => 'Role *',
        'reporting_manager_employee_code' => 'Reporting Manager Employee Code',
        'joining_date' => 'Joining Date *',
        'salary' => 'Salary *',
        'daily_allowance' => 'Daily Allowance *',
        'travel_allowance_type' => 'Travel Allowance Type *',
        'monthly_travel_expense_limit' => 'Monthly Travel Expense Limit',
        'company_card_issued' => 'Company Card Issued *',
        'company_card_last_four' => 'Company Card Last 4 Digits',
        'aadhaar_number' => 'Aadhaar Number *',
        'pan_number' => 'PAN Number *',
        'bank_name' => 'Bank Name *',
        'account_number' => 'Account Number *',
        'ifsc_code' => 'IFSC Code *',
        'status' => 'Status *',
    ];

    /** @return list<string> */
    public static function allColumns(): array
    {
        return self::ALL_COLUMNS;
    }

    /** @return list<string> */
    public static function columnLabels(): array
    {
        return array_map(
            fn (string $column): string => self::COLUMN_LABELS[$column],
            self::allColumns(),
        );
    }
}
