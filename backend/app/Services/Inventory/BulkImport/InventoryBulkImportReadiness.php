<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Models\FinishedProduct;
use App\Models\PackagingMaterial;
use App\Models\RawMaterial;
use App\Models\SemiFinishedMaterial;

final class InventoryBulkImportReadiness
{
    /**
     * @return array{
     *     raw_material_count:int,
     *     packaging_material_count:int,
     *     semi_finished_count:int,
     *     finished_product_count:int,
     *     component_master_count:int,
     *     bom_ready:bool,
     *     bom_block_reason:?string
     * }
     */
    public function snapshot(): array
    {
        $raw = RawMaterial::query()->count();
        $packaging = PackagingMaterial::query()->count();
        $semi = SemiFinishedMaterial::query()->count();
        $finished = FinishedProduct::query()->count();
        $components = $raw + $packaging + $semi;

        $bomReady = $finished > 0 && $components > 0;
        $reason = null;

        if (! $bomReady) {
            $missing = [];
            if ($components === 0) {
                $missing[] = 'at least one Raw / Packaging / Semi-Finished master';
            }
            if ($finished === 0) {
                $missing[] = 'at least one Finished Product Master';
            }
            $reason = 'BOM import is blocked until '.$this->joinList($missing).' exist.';
        }

        return [
            'raw_material_count' => $raw,
            'packaging_material_count' => $packaging,
            'semi_finished_count' => $semi,
            'finished_product_count' => $finished,
            'component_master_count' => $components,
            'bom_ready' => $bomReady,
            'bom_block_reason' => $reason,
        ];
    }

    public function canRun(InventoryBulkImportType $type): bool
    {
        if ($type !== InventoryBulkImportType::Bom) {
            return true;
        }

        return (bool) $this->snapshot()['bom_ready'];
    }

    public function blockReason(InventoryBulkImportType $type): ?string
    {
        if ($this->canRun($type)) {
            return null;
        }

        return $this->snapshot()['bom_block_reason'];
    }

    private function joinList(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
