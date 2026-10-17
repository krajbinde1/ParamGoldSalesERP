<?php

namespace App\Enums;

enum PurchaseMaterialType: string
{
    case RawMaterial = 'raw_material';
    case PackingMaterial = 'packing_material';
    case FinishedGoods = 'finished_goods';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::PackingMaterial => 'Packing Material',
            self::FinishedGoods => 'Finished Goods',
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
