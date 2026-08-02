<?php

namespace App\Services\Inventory\BulkImport\Contracts;

use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportPreview;
use App\Services\Inventory\BulkImport\InventoryBulkImportResult;

interface InventoryBulkImporter
{
    public function preview(string $path): InventoryBulkImportPreview;

    public function import(string $path, User $user): InventoryBulkImportResult;
}
