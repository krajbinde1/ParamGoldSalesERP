<?php

namespace App\Services\Products;

use App\Imports\RawSpreadsheetImport;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

final class ProductBulkImportReader
{
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'product_name' => 'product_name',
        'productname' => 'product_name',
        'product_name_' => 'product_name',
        'product_code' => 'product_code',
        'productcode' => 'product_code',
        'gst_percentage' => 'gst_percentage',
        'gst_percentage_' => 'gst_percentage',
        'gst' => 'gst_percentage',
        'gst_' => 'gst_percentage',
        'dealer_price' => 'dealer_price',
        'dealerprice' => 'dealer_price',
        'dealer_price_' => 'dealer_price',
        'nos_per_case' => 'nos_per_case',
        'nospercase' => 'nos_per_case',
        'nos_per_case_' => 'nos_per_case',
        'uom' => 'uom',
        'unit' => 'uom',
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

            if (in_array('product_name', $headers, true)) {
                return $index;
            }
        }

        throw ValidationException::withMessages([
            'file' => 'Unable to find product import column headers in the uploaded file.',
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
        foreach (ProductBulkImportTemplate::MANDATORY_COLUMNS as $required) {
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

            $mapped[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
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
