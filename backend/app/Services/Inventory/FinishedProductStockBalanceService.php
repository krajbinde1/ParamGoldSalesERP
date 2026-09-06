<?php

namespace App\Services\Inventory;

use App\Enums\StockItemType;
use App\Models\Product;
use App\Models\StockLedger;

/**
 * Rebuilds Current Finished Stock and Weighted Average Cost from finished-goods
 * ledger rows (opening, manufacturing in, dispatch/sales out, adjustments).
 * Does not create ledger rows or change BOM / opening-qty math.
 */
final class FinishedProductStockBalanceService
{
    public function syncFromLedgers(Product $product): Product
    {
        $ledgers = StockLedger::query()
            ->where('product_id', $product->id)
            ->where('item_type', StockItemType::FinishedProduct)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        if ($ledgers->isEmpty()) {
            return $product;
        }

        $qty = 0.0;
        $value = 0.0;

        foreach ($ledgers as $ledger) {
            $in = round((float) $ledger->quantity_in, 3);
            $out = round((float) $ledger->quantity_out, 3);
            $rate = (float) $ledger->rate;

            if ($in > 0.0001) {
                $inward = $this->inwardValue($ledger, $in, $out, $rate);
                $value = round($value + $inward, 2);
                $qty = round($qty + $in, 3);
            }

            if ($out > 0.0001) {
                $avg = $qty > 0.0001 ? round($value / $qty, 4) : $rate;
                $outward = $this->outwardValue($ledger, $out, $avg);
                $value = round(max(0, $value - $outward), 2);
                $qty = round($qty - $out, 3);
            }

            if ($qty <= 0.0001) {
                $qty = 0.0;
                $value = 0.0;
            }
        }

        $qty = max(0.0, round($qty, 3));
        $wac = $qty > 0.0001 ? round($value / $qty, 4) : 0.0;

        $product->current_finished_stock = $qty;
        $product->weighted_average_cost = $wac;
        $product->save();

        return $product;
    }

    private function inwardValue(StockLedger $ledger, float $in, float $out, float $rate): float
    {
        if ($ledger->inward_value !== null) {
            return round((float) $ledger->inward_value, 2);
        }

        if ($ledger->transaction_value !== null && $out <= 0.0001) {
            return round((float) $ledger->transaction_value, 2);
        }

        return round($in * $rate, 2);
    }

    private function outwardValue(StockLedger $ledger, float $out, float $averageRate): float
    {
        if ($ledger->outward_value !== null) {
            return round((float) $ledger->outward_value, 2);
        }

        return round($out * $averageRate, 2);
    }
}
