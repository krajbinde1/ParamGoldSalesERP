<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Imports\RawSpreadsheetImport;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

final class InventoryBulkImportReader
{
    /**
     * @return list<array{row_number:int,data:array<string, mixed>}>
     */
    public function read(string $path, InventoryBulkImportType $type): array
    {
        $sheetRows = Excel::toArray(new RawSpreadsheetImport, $path)[0] ?? [];

        if ($sheetRows === []) {
            throw ValidationException::withMessages([
                'file' => 'The import file is empty.',
            ]);
        }

        $headerRowIndex = $this->findHeaderRowIndex($sheetRows, $type);
        $headers = $this->normalizeHeaders($sheetRows[$headerRowIndex], $type);
        $this->assertValidHeaders($headers, $type);

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
    private function findHeaderRowIndex(array $sheetRows, InventoryBulkImportType $type): int
    {
        $anchor = match ($type) {
            InventoryBulkImportType::FinishedProduct => 'existing_product',
            InventoryBulkImportType::Bom => 'finished_product_code',
            default => 'material_name',
        };

        foreach ($sheetRows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->isRequirementIndicatorRow($row)) {
                continue;
            }

            $headers = $this->normalizeHeaders($row, $type);

            if (in_array($anchor, $headers, true)) {
                return $index;
            }
        }

        throw ValidationException::withMessages([
            'file' => 'Unable to find import column headers in the uploaded file.',
        ]);
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow, InventoryBulkImportType $type): array
    {
        $aliases = InventoryBulkImportTemplate::headerAliases($type);

        return array_values(array_filter(array_map(function (?string $header) use ($aliases): string {
            if ($this->isMetaLabelCell($header)) {
                return '';
            }

            $normalized = Str::of((string) $header)
                ->trim()
                ->lower()
                ->before('(')
                ->replace(['*', '%'], '')
                ->replace(['/', '\\'], '_')
                ->replace(' ', '_')
                ->trim('_')
                ->toString();

            // Collapse repeated words from labels like "Batch Tracking Yes No"
            $normalized = preg_replace('/_yes_?no$/', '', $normalized) ?? $normalized;

            return $aliases[$normalized] ?? $normalized;
        }, $headerRow)));
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertValidHeaders(array $headers, InventoryBulkImportType $type): void
    {
        foreach (InventoryBulkImportTemplate::mandatoryColumns($type) as $required) {
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

            if (is_string($value) || is_numeric($value)) {
                $mapped[$header] = is_string($value) ? trim($value) : $value;
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
