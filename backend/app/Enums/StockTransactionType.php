<?php

namespace App\Enums;

enum StockTransactionType: string
{
    case OpeningStock = 'opening_stock';
    case Purchase = 'purchase';
    case RawMaterialInward = 'raw_material_inward';
    case PackagingMaterialInward = 'packaging_material_inward';
    case PurchaseReturn = 'purchase_return';
    case ProductionConsumption = 'production_consumption';
    case ProductionOutput = 'production_output';
    case SemiFinishedProduction = 'semi_finished_production';
    case StockAdjustment = 'stock_adjustment';
    case Damage = 'damage';
    case Return = 'return';
    case BatchReversal = 'batch_reversal';
    case Dispatch = 'dispatch';

    public function label(): string
    {
        return match ($this) {
            self::OpeningStock => 'Opening Stock',
            self::Purchase => 'Purchase',
            self::RawMaterialInward => 'Raw Material Inward',
            self::PackagingMaterialInward => 'Packaging Material Inward',
            self::PurchaseReturn => 'Purchase Return',
            self::ProductionConsumption => 'Production Consumption',
            self::ProductionOutput => 'Finished Product Production',
            self::SemiFinishedProduction => 'Semi-Finished Production',
            self::StockAdjustment => 'Stock Adjustment',
            self::Damage => 'Damage',
            self::Return => 'Return',
            self::BatchReversal => 'Batch Reversal',
            self::Dispatch => 'Dispatch',
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
