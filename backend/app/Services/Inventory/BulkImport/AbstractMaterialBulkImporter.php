<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\User;
use App\Services\Inventory\BulkImport\Contracts\InventoryBulkImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class AbstractMaterialBulkImporter implements InventoryBulkImporter
{
    use ParsesImportValues;

    public function __construct(
        protected readonly InventoryBulkImportReader $reader = new InventoryBulkImportReader,
        protected readonly int $chunkSize = 100,
    ) {}

    abstract protected function type(): InventoryBulkImportType;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $seenNames
     */
    abstract protected function validateRow(array $data, array &$seenNames): ?string;

    /**
     * @param  array<string, mixed>  $data
     * @return array{imported:bool,opening_ledger:bool,stock_updated:bool,skipped:bool,mapping?:array<string, mixed>}
     */
    abstract protected function persistRow(array $data, User $user): array;

    public function preview(string $path): InventoryBulkImportPreview
    {
        $rows = $this->reader->read($path, $this->type());
        $seenNames = [];
        $previewRows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;

        $ignored = 0;

        foreach ($rows as $row) {
            if ($this->shouldIgnoreRow($row['data'])) {
                $ignored++;

                continue;
            }

            $error = $this->validateRow($row['data'], $seenNames);
            $isValid = $error === null;
            $isDuplicate = $error !== null && (
                Str::contains(Str::lower($error), 'duplicate')
                || Str::contains(Str::lower($error), 'already exists')
                || Str::contains(Str::lower($error), 'already linked')
            );

            if ($isValid) {
                $valid++;
                $status = 'valid';
                $action = 'import';
            } else {
                $invalid++;
                if ($isDuplicate) {
                    $duplicate++;
                }
                $status = $isDuplicate ? 'duplicate' : 'invalid';
                $action = 'skip';
            }

            $previewRows[] = [
                'row_number' => $row['row_number'],
                'data' => $row['data'],
                'is_valid' => $isValid,
                'action' => $action,
                'status' => $status,
                'error' => $error,
                'warning' => null,
            ];
        }

        $considered = count($rows) - $ignored;

        return new InventoryBulkImportPreview(
            rows: $previewRows,
            counts: [
                'total' => $considered,
                'valid' => $valid,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
                'ignored' => $ignored,
                'to_import' => $valid,
                'to_skip' => $invalid,
            ],
        );
    }

    public function import(string $path, User $user): InventoryBulkImportResult
    {
        @set_time_limit(0);

        $rows = $this->reader->read($path, $this->type());
        $seenNames = [];
        $imported = 0;
        $skipped = 0;
        $openingLedgerCreated = 0;
        $stockUpdated = 0;
        $errors = [];
        $mappings = [];

        $consideredRows = 0;

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            foreach ($chunk as $row) {
                if ($this->shouldIgnoreRow($row['data'])) {
                    continue;
                }

                $consideredRows++;
                $error = $this->validateRow($row['data'], $seenNames);

                if ($error !== null) {
                    $skipped++;
                    $errors[] = new InventoryBulkImportRowError(
                        rowNumber: $row['row_number'],
                        rowData: $row['data'],
                        reason: $error,
                    );

                    continue;
                }

                try {
                    $result = DB::transaction(fn (): array => $this->persistRow($row['data'], $user));

                    if ($result['skipped'] ?? false) {
                        $skipped++;
                        $errors[] = new InventoryBulkImportRowError(
                            rowNumber: $row['row_number'],
                            rowData: $row['data'],
                            reason: 'Row skipped during import.',
                        );

                        continue;
                    }

                    $imported++;
                    if ($result['opening_ledger'] ?? false) {
                        $openingLedgerCreated++;
                    }
                    if ($result['stock_updated'] ?? false) {
                        $stockUpdated++;
                    }
                    if (isset($result['mapping']) && is_array($result['mapping'])) {
                        $mappings[] = $result['mapping'];
                    }
                } catch (\Throwable $exception) {
                    $skipped++;
                    $errors[] = new InventoryBulkImportRowError(
                        rowNumber: $row['row_number'],
                        rowData: $row['data'],
                        reason: $exception instanceof ValidationException
                            ? (collect($exception->errors())->flatten()->first() ?? 'Unable to import this row.')
                            : ($exception->getMessage() !== '' ? $exception->getMessage() : 'Unable to import this row.'),
                    );
                }
            }
        }

        return new InventoryBulkImportResult(
            totalRows: $consideredRows,
            imported: $imported,
            skipped: $skipped,
            failed: count($errors),
            openingLedgerCreated: $openingLedgerCreated,
            stockUpdated: $stockUpdated,
            errors: $errors,
            mappings: $mappings,
        );
    }

    /**
     * Rows ignored entirely (e.g. pre-filled template lines with no opening values).
     *
     * @param  array<string, mixed>  $data
     */
    protected function shouldIgnoreRow(array $data): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{quantity:float,value:float,date:?string,remarks:string}|string
     */
    protected function resolveOpening(array $data): array|string
    {
        $qty = $this->parseDecimal($data['opening_quantity'] ?? null, 0.0);
        $value = $this->parseDecimal($data['opening_value'] ?? null, 0.0);

        if ($qty === null) {
            return 'Opening Quantity must be a valid number.';
        }

        if ($value === null) {
            return 'Opening Value must be a valid number.';
        }

        if ($qty < 0) {
            return 'Opening Quantity cannot be negative.';
        }

        if ($value < 0) {
            return 'Opening Value cannot be negative.';
        }

        if ($qty <= 0 && $value > 0) {
            return 'Opening Value must be zero when Opening Quantity is zero.';
        }

        $date = null;
        if (! $this->blank($data['opening_date'] ?? null)) {
            $date = $this->parseDate($data['opening_date']);
            if ($date === null) {
                return 'Opening Date is invalid.';
            }
        }

        if ($qty > 0) {
            if ($value <= 0) {
                return 'Opening Value must be greater than zero when Opening Quantity is greater than zero.';
            }

            if ($date === null) {
                return 'Opening Date is required when Opening Quantity is greater than zero.';
            }
        }

        return [
            'quantity' => round($qty, 3),
            'value' => round($value, 2),
            'date' => $date,
            'remarks' => 'Bulk Import',
        ];
    }

    protected function normalizeNameKey(string $name): string
    {
        return Str::lower(trim($name));
    }
}
