<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\User;
use App\Services\Inventory\BulkImport\Contracts\InventoryBulkImporter;
use Illuminate\Validation\ValidationException;

final class InventoryBulkImportManager
{
    public function __construct(
        private readonly InventoryBulkImportReadiness $readiness = new InventoryBulkImportReadiness,
    ) {}

    public function importer(InventoryBulkImportType $type): InventoryBulkImporter
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => app(RawMaterialBulkImporter::class),
            InventoryBulkImportType::PackagingMaterial => app(PackagingMaterialBulkImporter::class),
            InventoryBulkImportType::SemiFinished => app(SemiFinishedMaterialBulkImporter::class),
            InventoryBulkImportType::FinishedProduct => app(FinishedProductBulkImporter::class),
            InventoryBulkImportType::Bom => app(BomBulkImporter::class),
        };
    }

    public function preview(string $path, InventoryBulkImportType $type): InventoryBulkImportPreview
    {
        $this->assertAllowed($type);

        return $this->importer($type)->preview($path);
    }

    public function import(string $path, InventoryBulkImportType $type, User $user): InventoryBulkImportResult
    {
        $this->assertAllowed($type);

        return $this->importer($type)->import($path, $user);
    }

    private function assertAllowed(InventoryBulkImportType $type): void
    {
        if ($this->readiness->canRun($type)) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => $this->readiness->blockReason($type) ?? 'This import step is not available yet.',
        ]);
    }
}
