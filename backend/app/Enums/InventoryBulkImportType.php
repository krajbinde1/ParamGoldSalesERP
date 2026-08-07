<?php

namespace App\Enums;

enum InventoryBulkImportType: string
{
    case RawMaterial = 'raw_material';
    case PackagingMaterial = 'packaging_material';
    case SemiFinished = 'semi_finished';
    case FinishedProduct = 'finished_product';
    case FinishedGoodsOpeningStock = 'finished_goods_opening_stock';
    case Bom = 'bom';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Import Raw Materials',
            self::PackagingMaterial => 'Import Packaging Materials',
            self::SemiFinished => 'Import Semi-Finished Materials',
            self::FinishedProduct => 'Import Finished Products',
            self::FinishedGoodsOpeningStock => 'Import Finished Goods Opening Stock',
            self::Bom => 'Import BOM',
        };
    }

    /**
     * Short card heading used on the Inventory Import hub.
     */
    public function cardTitle(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::PackagingMaterial => 'Packaging Material',
            self::SemiFinished => 'Semi-Finished Material',
            self::FinishedProduct => 'Finished Product',
            self::FinishedGoodsOpeningStock => 'FG Opening Stock',
            self::Bom => 'BOM',
        };
    }

    /**
     * Auto-generated code prefix shown in UI (codes are never entered in master templates).
     */
    public function codePrefix(): ?string
    {
        return match ($this) {
            self::RawMaterial => 'RM',
            self::PackagingMaterial => 'PK',
            self::SemiFinished => 'SFM',
            self::FinishedProduct => 'FP',
            self::FinishedGoodsOpeningStock => 'FP',
            self::Bom => 'BOM',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RawMaterial => 'Import raw material masters with opening stock. Material codes (RM) are auto-generated. Opening stock posts to inventory / stock ledger only — not Material Inward.',
            self::PackagingMaterial => 'Import packaging material masters with opening stock. Packaging codes (PK) are auto-generated. Opening stock updates inventory and ledger without creating Material Inward.',
            self::SemiFinished => 'Import semi-finished material masters with opening stock. Codes (SFM) are auto-generated. Opening quantity and value post to inventory / stock ledger.',
            self::FinishedProduct => 'Enable Finished Goods Inventory on existing Sales Products. Optional FP codes are auto-generated. Opening stock updates finished goods inventory / ledger — does not create sales products.',
            self::FinishedGoodsOpeningStock => 'Add opening stock for existing Sales Products. Template is built from Sales Operations → Products. Match by Product Code. Does not create products, production entries, or material inwards.',
            self::Bom => 'Import bill of materials using generated master codes (RM / PK / SFM / FP). Requires finished products and at least one component master.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
