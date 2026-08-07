<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryBulkImportType;
use App\Enums\InventoryUnit;

final class InventoryBulkImportTemplate
{
    /**
     * @return list<string>
     */
    public static function mandatoryColumns(InventoryBulkImportType $type): array
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial,
            InventoryBulkImportType::PackagingMaterial => [
                'material_name',
                'unit',
            ],
            InventoryBulkImportType::SemiFinished => [
                'material_name',
                'unit',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'existing_product',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'product_code',
                'opening_quantity',
                'opening_value',
                'opening_date',
            ],
            InventoryBulkImportType::Bom => [
                'finished_product_code',
                'material_type',
                'material_code',
                'quantity',
                'unit',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function allColumns(InventoryBulkImportType $type): array
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => [
                'material_name',
                'unit',
                'minimum_stock',
                'opening_quantity',
                'opening_value',
                'opening_date',
                'remarks',
            ],
            InventoryBulkImportType::PackagingMaterial => [
                'material_name',
                'unit',
                'minimum_stock',
                'opening_quantity',
                'opening_value',
                'opening_date',
                'remarks',
            ],
            InventoryBulkImportType::SemiFinished => [
                'material_name',
                'unit',
                'minimum_stock',
                'opening_quantity',
                'opening_value',
                'opening_date',
                'remarks',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'existing_product',
                'minimum_stock',
                'opening_quantity',
                'opening_value',
                'opening_date',
                'remarks',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'product_code',
                'product_name',
                'opening_quantity',
                'opening_value',
                'opening_date',
            ],
            InventoryBulkImportType::Bom => [
                'finished_product_code',
                'material_type',
                'material_code',
                'material_name',
                'quantity',
                'unit',
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function columnLabels(InventoryBulkImportType $type): array
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => [
                'material_name' => 'Material Name *',
                'unit' => 'Unit *',
                'minimum_stock' => 'Minimum Stock',
                'opening_quantity' => 'Opening Stock Quantity',
                'opening_value' => 'Opening Stock Value',
                'opening_date' => 'Opening Stock Date',
                'remarks' => 'Remarks',
            ],
            InventoryBulkImportType::PackagingMaterial => [
                'material_name' => 'Material Name *',
                'unit' => 'Unit *',
                'minimum_stock' => 'Minimum Stock',
                'opening_quantity' => 'Opening Stock Quantity',
                'opening_value' => 'Opening Stock Value',
                'opening_date' => 'Opening Stock Date',
                'remarks' => 'Remarks',
            ],
            InventoryBulkImportType::SemiFinished => [
                'material_name' => 'Material Name *',
                'unit' => 'Unit *',
                'minimum_stock' => 'Minimum Stock',
                'opening_quantity' => 'Opening Stock Quantity',
                'opening_value' => 'Opening Stock Value',
                'opening_date' => 'Opening Stock Date',
                'remarks' => 'Remarks',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'existing_product' => 'Existing Product Code / Name *',
                'minimum_stock' => 'Minimum Stock',
                'opening_quantity' => 'Opening Stock Quantity',
                'opening_value' => 'Opening Stock Value',
                'opening_date' => 'Opening Stock Date',
                'remarks' => 'Remarks',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'product_code' => 'Product Code',
                'product_name' => 'Product Name',
                'opening_quantity' => 'Opening Stock Quantity',
                'opening_value' => 'Opening Stock Value',
                'opening_date' => 'Opening Stock Date',
            ],
            InventoryBulkImportType::Bom => [
                'finished_product_code' => 'Finished Product Code *',
                'material_type' => 'Material Type *',
                'material_code' => 'Material Code *',
                'material_name' => 'Material Name (visual only)',
                'quantity' => 'Quantity *',
                'unit' => 'Unit *',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function sampleRow(InventoryBulkImportType $type): array
    {
        $unit = InventoryUnit::Kg->value;

        return match ($type) {
            InventoryBulkImportType::RawMaterial => [
                'Gold Grain Sample',
                $unit,
                '10',
                '100',
                '50000',
                now('Asia/Kolkata')->toDateString(),
                'Opening migration',
            ],
            InventoryBulkImportType::PackagingMaterial => [
                'Carton Box Sample',
                InventoryUnit::Nos->value,
                '50',
                '200',
                '4000',
                now('Asia/Kolkata')->toDateString(),
                'Opening migration',
            ],
            InventoryBulkImportType::SemiFinished => [
                'Premix Sample',
                $unit,
                '5',
                '25',
                '12500',
                now('Asia/Kolkata')->toDateString(),
                'Opening migration',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'PRD000001',
                '10',
                '50',
                '25000',
                now('Asia/Kolkata')->toDateString(),
                'Opening migration',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'PRD000001',
                'Sample Sales Product',
                '100',
                '50000',
                now('Asia/Kolkata')->toDateString(),
            ],
            InventoryBulkImportType::Bom => [
                'FP000001',
                'Raw Material',
                'RM000001',
                'Gold Grain Sample',
                '1.5',
                $unit,
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function headerAliases(InventoryBulkImportType $type): array
    {
        $aliases = [
            'material_name' => 'material_name',
            'materialname' => 'material_name',
            'name' => 'material_name',
            'unit' => 'unit',
            'uom' => 'unit',
            'minimum_stock' => 'minimum_stock',
            'min_stock' => 'minimum_stock',
            'minimumstock' => 'minimum_stock',
            'opening_quantity' => 'opening_quantity',
            'opening_qty' => 'opening_quantity',
            'openingquantity' => 'opening_quantity',
            'opening_stock' => 'opening_quantity',
            'opening_value' => 'opening_value',
            'openingvalue' => 'opening_value',
            'opening_date' => 'opening_date',
            'openingdate' => 'opening_date',
            'batch_tracking' => 'batch_tracking',
            'batch_tracking_yesno' => 'batch_tracking',
            'expiry_tracking' => 'expiry_tracking',
            'expiry_tracking_yesno' => 'expiry_tracking',
            'active' => 'active',
            'active_yesno' => 'active',
            'status' => 'active',
            'remarks' => 'remarks',
            'remark' => 'remarks',
            'existing_product' => 'existing_product',
            'existingproduct' => 'existing_product',
            'product' => 'existing_product',
            'product_name' => 'existing_product',
            'product_code' => 'existing_product',
            'finished_product' => 'finished_product_code',
            'finishedproduct' => 'finished_product_code',
            'finished_product_code' => 'finished_product_code',
            'finishedproductcode' => 'finished_product_code',
            'fp_code' => 'finished_product_code',
            'finished_product_name' => 'finished_product_name',
            'finishedproductname' => 'finished_product_name',
            'opening_stock_quantity' => 'opening_quantity',
            'opening_stock_value' => 'opening_value',
            'opening_stock_date' => 'opening_date',
            'material_type' => 'material_type',
            'materialtype' => 'material_type',
            'material_code' => 'material_code',
            'materialcode' => 'material_code',
            'code' => 'material_code',
            'quantity' => 'quantity',
            'qty' => 'quantity',
        ];

        // FG Opening Stock uses Sales Product codes/names (ProductResource source).
        if ($type === InventoryBulkImportType::FinishedGoodsOpeningStock) {
            $aliases['product_code'] = 'product_code';
            $aliases['product_name'] = 'product_name';
            $aliases['product'] = 'product_code';
            $aliases['name'] = 'product_name';
            $aliases['code'] = 'product_code';
            $aliases['finished_product_code'] = 'product_code';
            $aliases['finished_product_name'] = 'product_name';
            $aliases['finishedproductcode'] = 'product_code';
            $aliases['finishedproductname'] = 'product_name';
        }

        return $aliases;
    }

    public static function downloadFilename(InventoryBulkImportType $type): string
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => 'raw-material-import-template.xlsx',
            InventoryBulkImportType::PackagingMaterial => 'packaging-material-import-template.xlsx',
            InventoryBulkImportType::SemiFinished => 'semi-finished-material-import-template.xlsx',
            InventoryBulkImportType::FinishedProduct => 'finished-product-import-template.xlsx',
            InventoryBulkImportType::FinishedGoodsOpeningStock => 'finished-goods-opening-stock-template.xlsx',
            InventoryBulkImportType::Bom => 'bom-import-template.xlsx',
        };
    }

    /**
     * @return list<string>
     */
    public static function codeMappingColumns(InventoryBulkImportType $type): array
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => [
                'material_code',
                'material_name',
                'unit',
                'active',
            ],
            InventoryBulkImportType::PackagingMaterial => [
                'packaging_code',
                'packaging_name',
                'unit',
                'active',
            ],
            InventoryBulkImportType::SemiFinished => [
                'material_code',
                'material_name',
                'unit',
                'active',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'finished_product_code',
                'product_code',
                'product_name',
                'unit',
                'current_stock',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'product_code',
                'product_name',
                'opening_quantity',
                'opening_value',
                'opening_rate',
                'status',
            ],
            InventoryBulkImportType::Bom => [
                'finished_product_code',
                'material_type',
                'material_code',
                'material_name',
                'quantity',
                'unit',
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function codeMappingLabels(InventoryBulkImportType $type): array
    {
        return match ($type) {
            InventoryBulkImportType::RawMaterial => [
                'material_code' => 'Material Code',
                'material_name' => 'Material Name',
                'unit' => 'Unit',
                'active' => 'Active',
            ],
            InventoryBulkImportType::PackagingMaterial => [
                'packaging_code' => 'Packaging Code',
                'packaging_name' => 'Packaging Name',
                'unit' => 'Unit',
                'active' => 'Active',
            ],
            InventoryBulkImportType::SemiFinished => [
                'material_code' => 'Material Code',
                'material_name' => 'Material Name',
                'unit' => 'Unit',
                'active' => 'Active',
            ],
            InventoryBulkImportType::FinishedProduct => [
                'finished_product_code' => 'Finished Product Code',
                'product_code' => 'Existing Product Code',
                'product_name' => 'Existing Product Name',
                'unit' => 'Unit',
                'current_stock' => 'Current Stock',
            ],
            InventoryBulkImportType::FinishedGoodsOpeningStock => [
                'product_code' => 'Product Code',
                'product_name' => 'Product Name',
                'opening_quantity' => 'Opening Quantity',
                'opening_value' => 'Opening Value',
                'opening_rate' => 'Opening Rate',
                'status' => 'Status',
            ],
            InventoryBulkImportType::Bom => [
                'finished_product_code' => 'Finished Product Code',
                'material_type' => 'Material Type',
                'material_code' => 'Material Code',
                'material_name' => 'Material Name',
                'quantity' => 'Quantity',
                'unit' => 'Unit',
            ],
        };
    }
}
