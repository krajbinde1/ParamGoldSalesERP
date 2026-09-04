<?php

namespace App\Enums;

enum BomItemType: string
{
    case RawMaterial = 'raw_material';
    case PackagingMaterial = 'packaging_material';
    case SemiFinished = 'semi_finished';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::PackagingMaterial => 'Packaging Material',
            self::SemiFinished => 'Bulk / Semi-Finished',
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
