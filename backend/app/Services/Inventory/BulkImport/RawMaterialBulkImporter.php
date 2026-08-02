<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\Inventory\RawMaterialCreateService;
use Illuminate\Validation\ValidationException;

final class RawMaterialBulkImporter extends AbstractMaterialBulkImporter
{
    public function __construct(
        private readonly RawMaterialCreateService $createService = new RawMaterialCreateService,
        InventoryBulkImportReader $reader = new InventoryBulkImportReader,
        int $chunkSize = 100,
    ) {
        parent::__construct($reader, $chunkSize);
    }

    protected function type(): InventoryBulkImportType
    {
        return InventoryBulkImportType::RawMaterial;
    }

    protected function validateRow(array $data, array &$seenNames): ?string
    {
        $missing = $this->missingRequired(['material_name', 'unit'], $data);
        if ($missing !== []) {
            return 'Missing mandatory field: '.implode(', ', $missing).'.';
        }

        $name = $this->stringValue($data['material_name']);
        $nameKey = $this->normalizeNameKey($name);

        if (isset($seenNames[$nameKey])) {
            return 'Duplicate material name in Excel.';
        }
        $seenNames[$nameKey] = true;

        if (RawMaterial::query()->whereRaw('LOWER(material_name) = ?', [$nameKey])->exists()) {
            return 'Material name already exists in database.';
        }

        if ($this->resolveInventoryUnit($data['unit'] ?? null) === null) {
            return 'Unit does not exist / is not supported.';
        }

        $minimum = $this->parseDecimal($data['minimum_stock'] ?? null, 0.0);
        if ($minimum === null || $minimum < 0) {
            return 'Minimum Stock must be a non-negative number.';
        }

        if ($this->parseYesNo($data['batch_tracking'] ?? null, false) === null) {
            return 'Batch Tracking must be Yes or No.';
        }

        if ($this->parseYesNo($data['expiry_tracking'] ?? null, false) === null) {
            return 'Expiry Tracking must be Yes or No.';
        }

        if ($this->parseYesNo($data['active'] ?? null, true) === null) {
            return 'Active must be Yes or No.';
        }

        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            return $opening;
        }

        return null;
    }

    protected function persistRow(array $data, User $user): array
    {
        $opening = $this->resolveOpening($data);
        if (is_string($opening)) {
            throw ValidationException::withMessages(['import' => $opening]);
        }

        $material = $this->createService->create(
            materialData: [
                'material_name' => $this->stringValue($data['material_name']),
                'unit' => $this->resolveInventoryUnit($data['unit']),
                'minimum_stock' => $this->parseDecimal($data['minimum_stock'] ?? null, 0.0) ?? 0,
                'batch_tracking_enabled' => $this->parseYesNo($data['batch_tracking'] ?? null, false) ?? false,
                'expiry_tracking_enabled' => $this->parseYesNo($data['expiry_tracking'] ?? null, false) ?? false,
                'status' => $this->parseYesNo($data['active'] ?? null, true) ?? true,
                'remarks' => $this->blank($data['remarks'] ?? null) ? null : $this->stringValue($data['remarks']),
            ],
            opening: $opening,
            user: $user,
        );

        $hasOpening = (float) $opening['quantity'] > 0;

        return [
            'imported' => true,
            'opening_ledger' => $hasOpening,
            'stock_updated' => $hasOpening,
            'skipped' => false,
            'mapping' => [
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'unit' => $material->unit,
                'active' => $material->status ? 'Yes' : 'No',
            ],
        ];
    }
}
