<?php

namespace App\Enums;

enum StockAdjustmentType: string
{
    case StockIncrease = 'stock_increase';
    case StockDecrease = 'stock_decrease';
    case Damage = 'damage';
    case Expired = 'expired';
    case PhysicalStockCorrection = 'physical_stock_correction';
    case Return = 'return';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StockIncrease => 'Stock Increase',
            self::StockDecrease => 'Stock Decrease',
            self::Damage => 'Damage',
            self::Expired => 'Expired',
            self::PhysicalStockCorrection => 'Physical Stock Correction',
            self::Return => 'Return',
            self::Other => 'Other',
        };
    }

    public function increasesStock(): bool
    {
        return in_array($this, [self::StockIncrease, self::Return], true);
    }

    public function decreasesStock(): bool
    {
        return in_array($this, [
            self::StockDecrease,
            self::Damage,
            self::Expired,
        ], true);
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
