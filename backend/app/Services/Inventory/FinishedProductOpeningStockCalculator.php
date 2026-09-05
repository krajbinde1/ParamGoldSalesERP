<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class FinishedProductOpeningStockCalculator
{
    public function __construct(
        private readonly BOMCalculationService $bomCalculation = new BOMCalculationService,
    ) {}

    public function nosPerCase(Product $product): int
    {
        return max(0, (int) ($product->nos_per_case ?: 0));
    }

    public function averageCostPerNos(Product $product): ?float
    {
        $bom = $this->bomCalculation->getActiveBomForProduct($product);

        if ($bom === null) {
            return null;
        }

        $cost = $bom->formulaSummary()['estimated_cost_per_finished_unit'] ?? null;

        if ($cost === null) {
            return null;
        }

        $cost = round((float) $cost, 2);

        return $cost > 0 ? $cost : null;
    }

    public static function openingQtyNos(float $cases, int $nosPerCase): float
    {
        if ($cases <= 0 || $nosPerCase <= 0) {
            return 0.0;
        }

        return round($cases * $nosPerCase, 3);
    }

    public static function openingStockValue(float $qtyNos, float $averageCostPerNos): float
    {
        if ($qtyNos <= 0 || $averageCostPerNos <= 0) {
            return 0.0;
        }

        return round($qtyNos * $averageCostPerNos, 2);
    }

    public static function casesFromQty(float $qtyNos, int $nosPerCase): float
    {
        if ($qtyNos <= 0 || $nosPerCase <= 0) {
            return 0.0;
        }

        return round($qtyNos / $nosPerCase, 3);
    }

    /**
     * @return array{quantity: float, value: float, average_cost: float, date: string|null}
     */
    public function resolveForSave(Product $product, float $cases, ?string $date): array
    {
        $cases = round($cases, 3);

        if ($cases < 0) {
            throw ValidationException::withMessages([
                'opening_stock_cases' => 'Opening Stock (Cases) cannot be negative.',
            ]);
        }

        if ($cases <= 0) {
            return [
                'quantity' => 0.0,
                'value' => 0.0,
                'average_cost' => 0.0,
                'date' => $date,
            ];
        }

        $nosPerCase = $this->nosPerCase($product);
        if ($nosPerCase <= 0) {
            throw ValidationException::withMessages([
                'opening_stock_cases' => 'Nos Per Case must be set on the Sales Product before saving opening stock.',
            ]);
        }

        $averageCost = $this->averageCostPerNos($product);
        if ($averageCost === null) {
            throw ValidationException::withMessages([
                'opening_stock_cases' => 'Set an Active BOM for this product before saving opening stock.',
            ]);
        }

        if (blank($date)) {
            throw ValidationException::withMessages([
                'opening_date' => 'As On Date is required when Opening Stock is greater than zero.',
            ]);
        }

        $qty = self::openingQtyNos($cases, $nosPerCase);

        return [
            'quantity' => $qty,
            'value' => self::openingStockValue($qty, $averageCost),
            'average_cost' => $averageCost,
            'date' => $date,
        ];
    }
}
