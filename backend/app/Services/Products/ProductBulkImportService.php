<?php

namespace App\Services\Products;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductBulkImportService
{
    public function __construct(
        private readonly ProductBulkImportReader $reader = new ProductBulkImportReader,
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
            $validation = $this->validateRow($row['data']);

            return [
                'row_number' => $row['row_number'],
                'data' => $row['data'],
                'is_valid' => $validation === null,
                'action' => $validation === null ? $this->resolveAction($row['data']) : null,
                'error' => $validation,
            ];
        }, $rows);
    }

    public function import(string $path): ProductBulkImportResult
    {
        $rows = $this->reader->read($path);
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $row) {
            $validation = $this->validateRow($row['data']);

            if ($validation !== null) {
                $errors[] = new ProductBulkImportRowError(
                    rowNumber: $row['row_number'],
                    rowData: $row['data'],
                    reason: $validation,
                );

                continue;
            }

            try {
                $action = DB::transaction(fn (): string => $this->persistRow($row['data']));

                if ($action === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $exception) {
                $errors[] = new ProductBulkImportRowError(
                    rowNumber: $row['row_number'],
                    rowData: $row['data'],
                    reason: $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first() ?? 'Unable to import this row.'
                        : 'Unable to import this row.',
                );
            }
        }

        return new ProductBulkImportResult(
            totalRows: count($rows),
            created: $created,
            updated: $updated,
            errors: $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateRow(array $data): ?string
    {
        $missing = $this->missingMandatoryFields($data);

        if ($missing !== []) {
            return 'Missing mandatory field: '.implode(', ', $missing).'.';
        }

        $validator = Validator::make($data, [
            'product_name' => ['required', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255'],
            'gst_percentage' => ['required', 'numeric'],
            'dealer_price' => ['required', 'numeric', 'min:0'],
            'nos_per_case' => ['required', 'integer', 'min:1'],
            'uom' => ['nullable', 'string', 'max:30'],
            'status' => ['required', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return collect($validator->errors()->all())->first() ?? 'Invalid row data.';
        }

        if (! in_array($this->normalizeGst($data['gst_percentage']), ProductBulkImportTemplate::ALLOWED_GST, true)) {
            return 'GST % must be one of: 0, 5, 12, 18, 28.';
        }

        if (filled($data['uom'] ?? null) && ! in_array($data['uom'], ProductBulkImportTemplate::ALLOWED_UOM, true)) {
            return 'Unit must be one of the supported product units.';
        }

        if ($this->parseStatus($data['status']) === null) {
            return 'Status must be Active or Inactive.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAction(array $data): string
    {
        $productCode = $this->normalizeProductCode($data['product_code'] ?? null);

        if ($productCode !== null && Product::withTrashed()->where('product_code', $productCode)->exists()) {
            return 'update';
        }

        return 'create';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistRow(array $data): string
    {
        $payload = $this->buildPayload($data);
        $productCode = $this->normalizeProductCode($data['product_code'] ?? null);

        if ($productCode !== null) {
            $product = Product::withTrashed()->where('product_code', $productCode)->first();

            if ($product !== null) {
                if ($product->trashed()) {
                    $product->restore();
                }

                $product->update($payload);

                return 'updated';
            }

            $payload['product_code'] = $productCode;
            Product::query()->create($payload);

            return 'created';
        }

        Product::query()->create($payload);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildPayload(array $data): array
    {
        return [
            'product_name' => trim((string) $data['product_name']),
            'gst_percentage' => $this->normalizeGst($data['gst_percentage']),
            'dealer_price' => $data['dealer_price'],
            'nos_per_case' => (int) $data['nos_per_case'],
            'uom' => filled($data['uom'] ?? null) ? $data['uom'] : 'Piece',
            'status' => $this->parseStatus($data['status']) ?? true,
            'category' => 'General',
            'mrp' => 0,
            'distributor_price' => 0,
            'retail_price' => 0,
            'minimum_stock' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function missingMandatoryFields(array $data): array
    {
        $missing = [];

        foreach (ProductBulkImportTemplate::MANDATORY_COLUMNS as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function normalizeGst(mixed $value): string
    {
        $normalized = Str::of((string) $value)
            ->trim()
            ->replace('%', '')
            ->toString();

        if (is_numeric($normalized)) {
            return (string) (int) round((float) $normalized);
        }

        return $normalized;
    }

    private function normalizeProductCode(mixed $value): ?string
    {
        $code = Str::upper(trim((string) $value));

        return $code === '' ? null : $code;
    }

    private function parseStatus(mixed $value): ?bool
    {
        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return true;
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
