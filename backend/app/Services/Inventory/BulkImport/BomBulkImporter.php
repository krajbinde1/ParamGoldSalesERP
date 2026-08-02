<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\InventoryBulkImportType;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\FinishedProduct;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;
use App\Models\User;
use App\Services\Inventory\BOMCalculationService;
use App\Services\Inventory\BulkImport\Contracts\InventoryBulkImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BomBulkImporter implements InventoryBulkImporter
{
    use ParsesImportValues;

    public function __construct(
        private readonly InventoryBulkImportReader $reader = new InventoryBulkImportReader,
        private readonly BOMCalculationService $bomService = new BOMCalculationService,
        private readonly InventoryBulkImportReadiness $readiness = new InventoryBulkImportReadiness,
        private readonly int $chunkSize = 100,
    ) {}

    public function preview(string $path): InventoryBulkImportPreview
    {
        if (! $this->readiness->canRun(InventoryBulkImportType::Bom)) {
            throw ValidationException::withMessages([
                'file' => $this->readiness->blockReason(InventoryBulkImportType::Bom)
                    ?? 'BOM import is blocked until required masters exist.',
            ]);
        }

        $rows = $this->reader->read($path, InventoryBulkImportType::Bom);
        $context = $this->buildValidationContext($rows);

        $previewRows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;

        foreach ($rows as $row) {
            $error = $context['row_errors'][$row['row_number']] ?? null;
            $warning = $context['row_warnings'][$row['row_number']] ?? null;
            $isValid = $error === null;
            $isDuplicate = $error !== null && (
                Str::contains(Str::lower($error), 'duplicate')
                || Str::contains(Str::lower($error), 'already exists')
            );

            if ($isValid) {
                $valid++;
                $status = $warning !== null ? 'warning' : 'valid';
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
                'warning' => $warning,
            ];
        }

        return new InventoryBulkImportPreview(
            rows: $previewRows,
            counts: [
                'total' => count($rows),
                'valid' => $valid,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
                'to_import' => $valid,
                'to_skip' => $invalid,
            ],
        );
    }

    public function import(string $path, User $user): InventoryBulkImportResult
    {
        @set_time_limit(0);

        if (! $this->readiness->canRun(InventoryBulkImportType::Bom)) {
            throw ValidationException::withMessages([
                'file' => $this->readiness->blockReason(InventoryBulkImportType::Bom)
                    ?? 'BOM import is blocked until required masters exist.',
            ]);
        }

        $rows = $this->reader->read($path, InventoryBulkImportType::Bom);
        $context = $this->buildValidationContext($rows);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $mappings = [];

        foreach ($context['groups'] as $group) {
            $groupRowNumbers = array_column($group['rows'], 'row_number');
            $groupHasError = false;

            foreach ($groupRowNumbers as $rowNumber) {
                if (isset($context['row_errors'][$rowNumber])) {
                    $groupHasError = true;
                    break;
                }
            }

            if ($groupHasError || ($group['product'] ?? null) === null) {
                foreach ($group['rows'] as $row) {
                    $reason = $context['row_errors'][$row['row_number']] ?? 'BOM group has invalid rows and was skipped.';
                    $skipped++;
                    $errors[] = new InventoryBulkImportRowError(
                        rowNumber: $row['row_number'],
                        rowData: $row['data'],
                        reason: $reason,
                    );
                }

                continue;
            }

            try {
                DB::transaction(function () use ($group, $user): void {
                    $this->persistBomGroup($group, $user);
                });

                $imported += count($group['rows']);

                $group['product']?->loadMissing('finishedProduct');

                foreach ($group['rows'] as $row) {
                    $resolved = $row['resolved'] ?? null;
                    if ($resolved === null) {
                        continue;
                    }

                    $mappings[] = [
                        'finished_product_code' => $group['product']->finishedProduct?->finished_product_code
                            ?: $group['product']->product_code,
                        'material_type' => $resolved['item_type'] instanceof BomItemType
                            ? $resolved['item_type']->value
                            : (string) $resolved['item_type'],
                        'material_code' => $resolved['material_code'],
                        'material_name' => $resolved['material_name'],
                        'quantity' => $resolved['quantity'],
                        'unit' => $resolved['unit'],
                    ];
                }
            } catch (\Throwable $exception) {
                $reason = $exception instanceof ValidationException
                    ? (collect($exception->errors())->flatten()->first() ?? 'Unable to import this BOM.')
                    : ($exception->getMessage() !== '' ? $exception->getMessage() : 'Unable to import this BOM.');

                foreach ($group['rows'] as $row) {
                    $skipped++;
                    $errors[] = new InventoryBulkImportRowError(
                        rowNumber: $row['row_number'],
                        rowData: $row['data'],
                        reason: $reason,
                    );
                }
            }
        }

        return new InventoryBulkImportResult(
            totalRows: count($rows),
            imported: $imported,
            skipped: $skipped,
            failed: count($errors),
            openingLedgerCreated: 0,
            stockUpdated: 0,
            errors: $errors,
            mappings: $mappings,
        );
    }

    /**
     * @param  list<array{row_number:int,data:array<string, mixed>}>  $rows
     * @return array{
     *     groups: array<string, array{product:?Product, rows:list<array{row_number:int,data:array<string, mixed>,resolved?:array<string, mixed>}>}>,
     *     row_errors: array<int, string>,
     *     row_warnings: array<int, string>
     * }
     */
    private function buildValidationContext(array $rows): array
    {
        $groups = [];
        $rowErrors = [];
        $rowWarnings = [];
        $materialKeysByGroup = [];

        foreach ($rows as $row) {
            $data = $row['data'];
            $rowNumber = $row['row_number'];

            $missing = $this->missingRequired(
                ['finished_product_code', 'material_type', 'material_code', 'quantity', 'unit'],
                $data,
            );

            if ($missing !== []) {
                $rowErrors[$rowNumber] = 'Missing mandatory field: '.implode(', ', $missing).'.';
                $groupKey = 'invalid:'.$rowNumber;
                $groups[$groupKey] = [
                    'product' => null,
                    'rows' => [$row],
                ];

                continue;
            }

            $finishedLookup = $this->stringValue($data['finished_product_code'] ?? $data['finished_product'] ?? '');
            $product = $this->findFinishedProduct($finishedLookup);

            if ($product === null) {
                $rowErrors[$rowNumber] = 'Finished Product Code not found.';
                $groupKey = 'invalid:'.$rowNumber;
                $groups[$groupKey] = [
                    'product' => null,
                    'rows' => [$row],
                ];

                continue;
            }

            $itemType = $this->resolveMaterialType($data['material_type'] ?? null);
            if ($itemType === null) {
                $rowErrors[$rowNumber] = 'Invalid material type. Use Raw Material, Packaging Material, or Semi Finished.';
                $groupKey = (string) $product->id;
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $material = $this->findMaterialByCode($itemType, $this->stringValue($data['material_code']));
            if ($material === null) {
                $rowErrors[$rowNumber] = 'Material Code not found in the selected Material Type.';
                $groupKey = (string) $product->id;
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $excelName = $this->stringValue($data['material_name'] ?? '');
            if ($excelName !== '' && Str::lower($excelName) !== Str::lower($material['name'])) {
                $rowWarnings[$rowNumber] = 'Material Name mismatch — master name is "'.$material['name'].'". Matched by Material Code.';
            }

            if ($this->blank($data['quantity'] ?? null)) {
                $rowErrors[$rowNumber] = 'Quantity must be a number greater than zero.';
                $groupKey = (string) $product->id;
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $qty = $this->parseDecimal($data['quantity'], 0.0);
            if ($qty === null || $qty <= 0) {
                $rowErrors[$rowNumber] = 'Quantity must be a number greater than zero.';
                $groupKey = (string) $product->id;
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $unit = $this->resolveInventoryUnit($data['unit'] ?? null);
            if ($unit === null) {
                $rowErrors[$rowNumber] = 'Unit does not exist / is not supported.';
                $groupKey = (string) $product->id;
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $materialKey = $itemType->value.':'.$material['id'];
            $groupKey = (string) $product->id;
            $materialKeysByGroup[$groupKey] ??= [];

            if (isset($materialKeysByGroup[$groupKey][$materialKey])) {
                $rowErrors[$rowNumber] = 'Duplicate material row for the same Finished Product in Excel.';
                $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
                $groups[$groupKey]['rows'][] = $row;

                continue;
            }

            $materialKeysByGroup[$groupKey][$materialKey] = true;

            $resolved = [
                'item_type' => $itemType,
                'material_id' => $material['id'],
                'material_code' => $material['code'],
                'material_name' => $material['name'],
                'quantity' => $qty,
                'unit' => $unit,
            ];

            $groups[$groupKey] ??= ['product' => $product, 'rows' => []];
            $groups[$groupKey]['rows'][] = [
                'row_number' => $rowNumber,
                'data' => $data,
                'resolved' => $resolved,
            ];
        }

        return [
            'groups' => $groups,
            'row_errors' => $rowErrors,
            'row_warnings' => $rowWarnings,
        ];
    }

    /**
     * @param  array{product:Product, rows:list<array{row_number:int,data:array<string, mixed>,resolved?:array<string, mixed>}>}  $group
     */
    private function persistBomGroup(array $group, User $user): void
    {
        /** @var Product $product */
        $product = $group['product'];

        $itemsPayload = [];
        $sort = 1;

        foreach ($group['rows'] as $row) {
            $resolved = $row['resolved'] ?? null;
            if ($resolved === null) {
                throw ValidationException::withMessages([
                    'bom' => 'BOM row is missing resolved material data.',
                ]);
            }

            /** @var BomItemType $itemType */
            $itemType = $resolved['item_type'];

            $itemsPayload[] = [
                'item_type' => $itemType->value,
                'raw_material_id' => $itemType === BomItemType::RawMaterial ? $resolved['material_id'] : null,
                'packaging_material_id' => $itemType === BomItemType::PackagingMaterial ? $resolved['material_id'] : null,
                'semi_finished_id' => $itemType === BomItemType::SemiFinished ? $resolved['material_id'] : null,
                'required_quantity' => $resolved['quantity'],
                'unit' => $resolved['unit'],
                'is_optional' => false,
                'wastage_percentage' => 0,
                'sort_order' => $sort++,
            ];
        }

        $header = [
            'output_type' => BomOutputType::FinishedProduct->value,
            'product_id' => $product->id,
            'semi_finished_id' => null,
            'batch_quantity' => 1,
            'batch_unit' => 'Nos',
            'standard_batch_size' => 1,
            'output_quantity' => 1,
        ];

        $this->bomService->assertBomFormulaForSave($header, $itemsPayload, BomStatus::Active);

        $bom = Bom::query()->create([
            'output_type' => BomOutputType::FinishedProduct,
            'product_id' => $product->id,
            'semi_finished_id' => null,
            'standard_batch_size' => 1,
            'output_quantity' => 1,
            'batch_quantity' => 1,
            'batch_unit' => 'Nos',
            'effective_date' => now('Asia/Kolkata')->toDateString(),
            'status' => BomStatus::Active,
            'wastage_percentage' => 0,
            'notes' => 'Bulk Import',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        foreach ($itemsPayload as $item) {
            BomItem::query()->create([
                ...$item,
                'bom_id' => $bom->id,
            ]);
        }

        $bom = $bom->fresh(['items', 'product']);
        $this->bomService->syncCalculatedQuantities($bom->fresh(['items']));
        $this->bomService->ensureSingleActiveBom($bom->fresh(['product', 'items']));
    }

    private function findFinishedProduct(string $lookup): ?Product
    {
        $code = Str::upper(trim($lookup));

        $byFpCode = FinishedProduct::query()
            ->with('product')
            ->whereRaw('UPPER(finished_product_code) = ?', [$code])
            ->first();

        if ($byFpCode?->product !== null) {
            return $byFpCode->product;
        }

        // Compatibility: allow sales product_code for already-linked FG masters.
        return Product::query()
            ->inFinishedInventory()
            ->whereHas('finishedProduct')
            ->whereRaw('UPPER(product_code) = ?', [$code])
            ->first();
    }

    private function resolveMaterialType(mixed $value): ?BomItemType
    {
        $normalized = Str::of($this->stringValue($value))
            ->lower()
            ->replace(['-', '_'], ' ')
            ->squish()
            ->toString();

        return match ($normalized) {
            'raw material', 'rawmaterial', 'rm', 'raw' => BomItemType::RawMaterial,
            'packaging material', 'packagingmaterial', 'packaging', 'pk', 'pm' => BomItemType::PackagingMaterial,
            'semi finished', 'semifinished', 'semi finished material', 'sf', 'sfm' => BomItemType::SemiFinished,
            default => BomItemType::tryFrom($this->stringValue($value)),
        };
    }

    /**
     * @return array{id:int,code:string,name:string}|null
     */
    private function findMaterialByCode(BomItemType $type, string $code): ?array
    {
        $normalized = Str::upper(trim($code));

        $model = match ($type) {
            BomItemType::RawMaterial => RawMaterial::query()
                ->whereRaw('UPPER(material_code) = ?', [$normalized])
                ->first(),
            BomItemType::PackagingMaterial => PackagingMaterial::query()
                ->whereRaw('UPPER(packaging_code) = ?', [$normalized])
                ->first(),
            BomItemType::SemiFinished => SemiFinishedMaterial::query()
                ->whereRaw('UPPER(material_code) = ?', [$normalized])
                ->first(),
        };

        if ($model === null) {
            return null;
        }

        $name = match ($type) {
            BomItemType::RawMaterial => (string) $model->material_name,
            BomItemType::PackagingMaterial => (string) $model->packaging_name,
            BomItemType::SemiFinished => (string) $model->material_name,
        };

        $resolvedCode = match ($type) {
            BomItemType::RawMaterial, BomItemType::SemiFinished => (string) $model->material_code,
            BomItemType::PackagingMaterial => (string) $model->packaging_code,
        };

        return [
            'id' => (int) $model->getKey(),
            'code' => $resolvedCode,
            'name' => $name,
        ];
    }
}
