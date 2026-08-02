<?php

namespace App\Enums;

enum RawMaterialBatchStatus: string
{
    case Available = 'available';
    case LowStock = 'low_stock';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Blocked = 'blocked';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::LowStock => 'Low Stock',
            self::Exhausted => 'Exhausted',
            self::Expired => 'Expired',
            self::Blocked => 'Blocked',
            self::Rejected => 'Rejected',
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
