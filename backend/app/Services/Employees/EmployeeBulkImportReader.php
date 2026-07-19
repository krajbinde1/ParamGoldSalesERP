<?php

namespace App\Services\Employees;

use App\Imports\RawSpreadsheetImport;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

final class EmployeeBulkImportReader
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'employee_name' => 'full_name',
        'employeename' => 'full_name',
        'full_name' => 'full_name',
        'mobile_number' => 'mobile',
        'mobilenumber' => 'mobile',
        'mobile' => 'mobile',
        'email' => 'email',
        'role' => 'role',
        'reporting_manager_employee_code' => 'reporting_manager_employee_code',
        'reportingmanageremployeecode' => 'reporting_manager_employee_code',
        'reporting_manager_code' => 'reporting_manager_employee_code',
        'joining_date' => 'joining_date',
        'joiningdate' => 'joining_date',
        'salary' => 'salary',
        'daily_allowance' => 'daily_allowance',
        'dailyallowance' => 'daily_allowance',
        'travel_allowance_type' => 'travel_allowance_type',
        'travelallowancetype' => 'travel_allowance_type',
        'monthly_travel_expense_limit' => 'monthly_travel_expense_limit',
        'monthlytravelexpenselimit' => 'monthly_travel_expense_limit',
        'company_card_issued' => 'company_card_issued',
        'companycardissued' => 'company_card_issued',
        'company_card_last_four' => 'company_card_last_four',
        'company_card_last_4_digits' => 'company_card_last_four',
        'companycardlastfour' => 'company_card_last_four',
        'companycardlast4digits' => 'company_card_last_four',
        'aadhaar_number' => 'aadhaar_number',
        'aadhaarnumber' => 'aadhaar_number',
        'pan_number' => 'pan_number',
        'pannumber' => 'pan_number',
        'bank_name' => 'bank_name',
        'bankname' => 'bank_name',
        'account_number' => 'account_number',
        'accountnumber' => 'account_number',
        'ifsc_code' => 'ifsc_code',
        'ifsccode' => 'ifsc_code',
        'status' => 'status',
    ];

    /**
     * @return list<array{row_number:int,data:array<string, mixed>}>
     */
    public function read(string $path): array
    {
        $sheetRows = Excel::toArray(new RawSpreadsheetImport, $path)[0] ?? [];

        if ($sheetRows === []) {
            throw ValidationException::withMessages([
                'file' => 'The import file is empty.',
            ]);
        }

        $headerRowIndex = $this->findHeaderRowIndex($sheetRows);
        $headers = $this->normalizeHeaders($sheetRows[$headerRowIndex]);
        $this->assertValidHeaders($headers);

        $parsedRows = [];

        for ($index = $headerRowIndex + 1; $index < count($sheetRows); $index++) {
            $row = $sheetRows[$index];

            if (! is_array($row) || $this->isBlankRow($row) || $this->isExampleRow($row)) {
                continue;
            }

            $parsedRows[] = [
                'row_number' => $index + 1,
                'data' => $this->mapRow($headers, $row),
            ];
        }

        return $parsedRows;
    }

    /**
     * @param  array<int, array<int, mixed>>  $sheetRows
     */
    private function findHeaderRowIndex(array $sheetRows): int
    {
        foreach ($sheetRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->isRequirementIndicatorRow($row)) {
                continue;
            }

            $headers = $this->normalizeHeaders($row);

            if (in_array('full_name', $headers, true) || in_array('mobile', $headers, true)) {
                return $index;
            }
        }

        throw ValidationException::withMessages([
            'file' => 'Unable to find employee import column headers in the uploaded file.',
        ]);
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        return array_values(array_filter(array_map(function (?string $header): string {
            if ($this->isMetaLabelCell($header)) {
                return '';
            }

            $normalized = Str::of((string) $header)
                ->trim()
                ->lower()
                ->before('(')
                ->trim('*')
                ->replace(' ', '_')
                ->replace('%', '')
                ->trim('_')
                ->toString();

            return self::HEADER_ALIASES[$normalized] ?? $normalized;
        }, $headerRow)));
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertValidHeaders(array $headers): void
    {
        foreach (EmployeeBulkImportTemplate::MANDATORY_COLUMNS as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages([
                    'file' => "Missing required column: {$required}.",
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $value = $row[$index] ?? '';

            if ($value instanceof \DateTimeInterface) {
                $mapped[$header] = $value->format('Y-m-d');
            } else {
                $mapped[$header] = trim((string) $value);
            }
        }

        return $mapped;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $row
     */
    private function isExampleRow(array $row): bool
    {
        return in_array(Str::lower(trim((string) ($row[0] ?? ''))), ['example', 'sample'], true);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function isRequirementIndicatorRow(array $row): bool
    {
        $first = Str::lower(trim((string) ($row[0] ?? '')));

        if (in_array($first, ['requirement', 'requirements', 'type'], true)) {
            return true;
        }

        foreach ($row as $cell) {
            $value = Str::upper(trim((string) $cell));

            if (in_array($value, ['MANDATORY', 'OPTIONAL'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isMetaLabelCell(?string $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['column', 'columns', 'field', 'fields'], true);
    }
}
