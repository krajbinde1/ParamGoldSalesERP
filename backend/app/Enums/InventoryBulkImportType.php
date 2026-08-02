<?php

namespace App\Enums;

enum InventoryBulkImportType: string
{
    case RawMaterial = 'raw_material';
    case PackagingMaterial = 'packaging_material';
    case SemiFinished = 'semi_finished';
    case FinishedProduct = 'finished_product';
    case Bom = 'bom';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material Master',
            self::PackagingMaterial => 'Packaging Material Master',
            self::SemiFinished => 'Semi Finished Material Master',
            self::FinishedProduct => 'Finished Product Master',
            self::Bom => 'Bill Of Materials (BOM)',
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
