<?php

namespace App\Services\Dealers;

use App\Models\Dealer;
use App\Support\EmployeeCodeResolver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DealerBulkImportService
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'alt_mobile' => 'alternate_mobile',
    ];

    public function __construct(
        private readonly EmployeeCodeResolver $employeeCodeResolver = new EmployeeCodeResolver,
    ) {}

    public function import(string $path): DealerBulkImportResult
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read the uploaded file.',
            ]);
        }

        try {
            $headerRow = $this->readHeaderRow($handle);

            if ($headerRow === false) {
                throw ValidationException::withMessages([
                    'file' => 'The import file is empty.',
                ]);
            }

            $headers = $this->normalizeHeaders($headerRow);
            $this->assertValidHeaders($headers);

            $imported = 0;
            $errors = [];
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->isBlankRow($row) || $this->isExampleRow($row)) {
                    continue;
                }

                $data = $this->mapRow($headers, $row);
                $result = $this->importRow($data, $rowNumber);

                if ($result instanceof DealerBulkImportRowError) {
                    $errors[] = $result;

                    continue;
                }

                $imported++;
            }

            return new DealerBulkImportResult($imported, $errors);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|false
     */
    private function readHeaderRow($handle): array|false
    {
        $firstRow = fgetcsv($handle);

        if ($firstRow === false) {
            return false;
        }

        if ($this->isRequirementIndicatorRow($firstRow)) {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw ValidationException::withMessages([
                    'file' => 'The import file is missing column headers.',
                ]);
            }

            if ($this->isColumnLabelRow($headerRow)) {
                return $headerRow;
            }

            return $headerRow;
        }

        return $firstRow;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        return array_values(array_filter(array_map(function (?string $header): string {
            if ($this->isColumnLabelCell($header)) {
                return '';
            }

            $normalized = Str::of((string) $header)
                ->trim()
                ->lower()
                ->before('(')
                ->trim('*')
                ->replace(' ', '_')
                ->toString();

            return self::HEADER_ALIASES[$normalized] ?? $normalized;
        }, $headerRow)));
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertValidHeaders(array $headers): void
    {
        if (in_array('assigned_employee_mobile', $headers, true)) {
            throw ValidationException::withMessages([
                'file' => 'Use assigned_employee_code instead of assigned_employee_mobile.',
            ]);
        }

        foreach (DealerBulkImportTemplate::MANDATORY_COLUMNS as $required) {
            if (! in_array($required, $headers, true)) {
                throw ValidationException::withMessages([
                    'file' => "Missing required column: {$required}.",
                ]);
            }
        }
    }

    /**
     * @param  list<string|null>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $mapped[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importRow(array $data, int $rowNumber): DealerBulkImportRowError|true
    {
        $firmName = trim((string) ($data['firm_name'] ?? ''));
        $assignedCode = trim((string) ($data['assigned_employee_code'] ?? ''));

        $missingFields = $this->missingMandatoryFields($data);

        if ($missingFields !== []) {
            return new DealerBulkImportRowError(
                rowNumber: $rowNumber,
                firmName: $firmName,
                assignedEmployeeCode: $assignedCode,
                reason: 'Missing mandatory field: '.implode(', ', $missingFields).'.',
            );
        }

        $validator = Validator::make($data, [
            'firm_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
            'address' => ['required', 'string'],
            'state' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'taluka' => ['required', 'string', 'max:255'],
            'village' => ['required', 'string', 'max:255'],
            'pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'assigned_employee_code' => ['required', 'string', 'max:255'],
            'status' => ['required'],
            'dealer_code' => ['nullable', 'string', 'max:255'],
            'alternate_mobile' => ['nullable', 'regex:/^[6-9][0-9]{9}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'gst_no' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'outstanding' => ['nullable', 'numeric', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return new DealerBulkImportRowError(
                rowNumber: $rowNumber,
                firmName: $firmName,
                assignedEmployeeCode: $assignedCode,
                reason: collect($validator->errors()->all())->first() ?? 'Invalid row data.',
            );
        }

        $employee = $this->employeeCodeResolver->resolveAssignableEmployee($assignedCode);

        if ($employee === null) {
            return new DealerBulkImportRowError(
                rowNumber: $rowNumber,
                firmName: $firmName,
                assignedEmployeeCode: $assignedCode,
                reason: 'No active employee found for the assigned employee code.',
            );
        }

        if (Dealer::query()->where('mobile', $data['mobile'])->exists()) {
            return new DealerBulkImportRowError(
                rowNumber: $rowNumber,
                firmName: $firmName,
                assignedEmployeeCode: $assignedCode,
                reason: 'A dealer with this mobile number already exists.',
            );
        }

        if (filled($data['dealer_code'] ?? null) && Dealer::query()->where('dealer_code', strtoupper($data['dealer_code']))->exists()) {
            return new DealerBulkImportRowError(
                rowNumber: $rowNumber,
                firmName: $firmName,
                assignedEmployeeCode: $assignedCode,
                reason: 'A dealer with this dealer code already exists.',
            );
        }

        $payload = [
            'firm_name' => $data['firm_name'],
            'owner_name' => $data['owner_name'],
            'mobile' => $data['mobile'],
            'address' => $data['address'],
            'state' => $data['state'],
            'district' => $data['district'],
            'taluka' => $data['taluka'],
            'village' => $data['village'],
            'pincode' => $data['pincode'],
            'status' => $this->parseStatus($data['status']),
            'assigned_employee_id' => $employee->id,
            'dealer_type' => 'Retailer',
            'credit_limit' => filled($data['credit_limit'] ?? null) ? $data['credit_limit'] : 0,
            'outstanding' => filled($data['outstanding'] ?? null) ? $data['outstanding'] : 0,
            'alternate_mobile' => filled($data['alternate_mobile'] ?? null) ? $data['alternate_mobile'] : null,
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'gst_no' => filled($data['gst_no'] ?? null) ? strtoupper($data['gst_no']) : null,
            'latitude' => filled($data['latitude'] ?? null) ? $data['latitude'] : null,
            'longitude' => filled($data['longitude'] ?? null) ? $data['longitude'] : null,
        ];

        if (filled($data['dealer_code'] ?? null)) {
            $payload['dealer_code'] = strtoupper(trim($data['dealer_code']));
        }

        Dealer::query()->create($payload);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function missingMandatoryFields(array $data): array
    {
        $missing = [];

        foreach (DealerBulkImportTemplate::MANDATORY_COLUMNS as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function parseStatus(mixed $value): bool
    {
        $normalized = Str::lower(trim((string) $value));

        return ! in_array($normalized, ['0', 'false', 'inactive', 'no'], true);
    }

    /**
     * @param  list<string|null>  $row
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
     * @param  list<string|null>  $row
     */
    private function isExampleRow(array $row): bool
    {
        return in_array(Str::lower(trim((string) ($row[0] ?? ''))), ['example', 'sample'], true);
    }

    /**
     * @param  list<string|null>  $row
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

    /**
     * @param  list<string|null>  $row
     */
    private function isColumnLabelRow(array $row): bool
    {
        return $this->isColumnLabelCell($row[0] ?? null);
    }

    private function isColumnLabelCell(?string $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['column', 'columns', 'field', 'fields'], true);
    }
}
