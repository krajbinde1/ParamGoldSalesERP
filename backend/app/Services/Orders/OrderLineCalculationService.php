<?php

namespace App\Services\Orders;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class OrderLineCalculationService
{
    /** @var list<int> */
    public const ALLOWED_GST = [0, 5, 12, 18, 28];

    public const RATE_TYPE_PRICE_LIST = 'price_list';

    public const RATE_TYPE_FIXED = 'fixed_rate';

    /**
     * @return array{
     *     product_id:int,
     *     case_quantity:int,
     *     nos_per_case:int,
     *     total_quantity_nos:int,
     *     quantity:float,
     *     unit:string,
     *     rate_per_no:float,
     *     rate_type:string,
     *     rate:float,
     *     discount_percentage:float,
     *     discount_amount:float,
     *     gst_percentage:int,
     *     base_amount:float,
     *     taxable_amount:float,
     *     gst_amount:float,
     *     final_amount:float,
     *     line_total:float
     * }
     */
    public function calculateForProduct(
        Product $product,
        int $caseQuantity,
        float $ratePerNo,
        float $requestedDiscountPercentage,
        float $requestedGstPercentage,
        bool $enforceDiscountRule = true,
        string $rateType = self::RATE_TYPE_PRICE_LIST,
    ): array {
        if ($caseQuantity < 1) {
            throw ValidationException::withMessages([
                'case_quantity' => 'Case quantity must be at least 1.',
            ]);
        }

        $nosPerCase = (int) $product->nos_per_case;

        if ($nosPerCase < 1) {
            throw ValidationException::withMessages([
                'product_id' => 'Product Nos Per Case must be at least 1.',
            ]);
        }

        $rateType = $this->normalizeRateType($rateType);

        if ($rateType === self::RATE_TYPE_PRICE_LIST) {
            // Price list rate always comes from product master dealer_price.
            $ratePerNo = (float) $product->dealer_price;
        }

        if ($ratePerNo < 0) {
            throw ValidationException::withMessages([
                'rate_per_no' => 'Rate per No must be zero or greater.',
            ]);
        }

        $gstPercentage = $this->normalizeGst($requestedGstPercentage);

        if ($gstPercentage === null) {
            throw ValidationException::withMessages([
                'gst_percentage' => 'GST must be one of the allowed values.',
            ]);
        }

        $discountPercentage = $requestedDiscountPercentage;

        if ($rateType === self::RATE_TYPE_FIXED) {
            // Fixed rate never allows discount.
            $discountPercentage = 0.0;
        } elseif ($discountPercentage < 0 || $discountPercentage > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discount must be between 0 and 100.',
            ]);
        }

        if (
            $rateType === self::RATE_TYPE_PRICE_LIST
            && $enforceDiscountRule
            && abs($ratePerNo - (float) $product->dealer_price) >= 0.001
        ) {
            $discountPercentage = 0.0;
        }

        if ($discountPercentage < 0 || $discountPercentage > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discount must be between 0 and 100.',
            ]);
        }

        $totalQuantityNos = $caseQuantity * $nosPerCase;
        $baseAmount = round($totalQuantityNos * $ratePerNo, 2);
        $discountAmount = round($baseAmount * $discountPercentage / 100, 2);
        $taxableAmount = round($baseAmount - $discountAmount, 2);
        $gstAmount = round($taxableAmount * $gstPercentage / 100, 2);
        $finalAmount = round($taxableAmount + $gstAmount, 2);

        return [
            'product_id' => $product->id,
            'case_quantity' => $caseQuantity,
            'nos_per_case' => $nosPerCase,
            'total_quantity_nos' => $totalQuantityNos,
            'quantity' => (float) $totalQuantityNos,
            'unit' => $product->uom,
            'rate_per_no' => round($ratePerNo, 2),
            'rate_type' => $rateType,
            'rate' => round($ratePerNo, 2),
            'discount_percentage' => round($discountPercentage, 2),
            'discount_amount' => $discountAmount,
            'gst_percentage' => $gstPercentage,
            'base_amount' => $baseAmount,
            'taxable_amount' => $taxableAmount,
            'gst_amount' => $gstAmount,
            'final_amount' => $finalAmount,
            'line_total' => $finalAmount,
        ];
    }

    public function normalizeRateType(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === self::RATE_TYPE_FIXED
            ? self::RATE_TYPE_FIXED
            : self::RATE_TYPE_PRICE_LIST;
    }

    /**
     * @return array{
     *     base_amount:float,
     *     discount_amount:float,
     *     taxable_amount:float,
     *     gst_amount:float,
     *     final_amount:float
     * }
     */
    public function resolveStoredAmounts(object $item): array
    {
        if ($item->base_amount !== null && $item->taxable_amount !== null) {
            return [
                'base_amount' => round((float) $item->base_amount, 2),
                'discount_amount' => round((float) ($item->discount_amount ?? 0), 2),
                'taxable_amount' => round((float) $item->taxable_amount, 2),
                'gst_amount' => round((float) ($item->gst_amount ?? 0), 2),
                'final_amount' => round((float) ($item->final_amount ?? $item->line_total ?? 0), 2),
            ];
        }

        $baseAmount = round((float) $item->quantity * (float) $item->rate, 2);
        $discountAmount = round($baseAmount * ((float) $item->discount_percentage / 100), 2);
        $taxableAmount = round($baseAmount - $discountAmount, 2);
        $gstAmount = round($taxableAmount * ((float) $item->gst_percentage / 100), 2);
        $finalAmount = round($taxableAmount + $gstAmount, 2);

        return [
            'base_amount' => $baseAmount,
            'discount_amount' => $discountAmount,
            'taxable_amount' => $taxableAmount,
            'gst_amount' => $gstAmount,
            'final_amount' => $finalAmount,
        ];
    }

    public function normalizeGst(float $value): ?int
    {
        foreach (self::ALLOWED_GST as $gst) {
            if (abs($value - $gst) < 0.001) {
                return $gst;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentLineItem(object $item): array
    {
        $amounts = $this->resolveStoredAmounts($item);
        $caseQuantity = (int) ($item->case_quantity ?? 1);
        $nosPerCase = (int) ($item->nos_per_case ?? 1);
        $totalQuantityNos = (int) ($item->total_quantity_nos ?? round((float) $item->quantity));
        $ratePerNo = (float) ($item->rate_per_no ?? $item->rate);
        $rateType = $this->normalizeRateType(
            isset($item->rate_type) ? (string) $item->rate_type : null
        );
        // Legacy rows without rate_type: treat non-list rates as fixed.
        if (
            (! isset($item->rate_type) || $item->rate_type === null || $item->rate_type === '')
            && $item->product !== null
            && abs($ratePerNo - (float) $item->product->dealer_price) >= 0.001
        ) {
            $rateType = self::RATE_TYPE_FIXED;
        }

        return [
            'product_id' => $item->product_id,
            'product_name' => $item->product?->product_name,
            'product_code' => $item->product?->product_code,
            'case_quantity' => $caseQuantity,
            'nos_per_case' => $nosPerCase,
            'total_quantity_nos' => $totalQuantityNos,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'rate_per_no' => $ratePerNo,
            'rate_type' => $rateType,
            'rate_type_label' => $rateType === self::RATE_TYPE_FIXED ? 'Fixed Rate' : 'Price List',
            'original_dealer_price' => $item->product === null
                ? null
                : (float) $item->product->dealer_price,
            'rate' => (float) $item->rate,
            'discount_percentage' => $rateType === self::RATE_TYPE_FIXED
                ? 0.0
                : (float) $item->discount_percentage,
            'gst_percentage' => (float) $item->gst_percentage,
            'base_amount' => $amounts['base_amount'],
            'discount_amount' => $rateType === self::RATE_TYPE_FIXED
                ? 0.0
                : $amounts['discount_amount'],
            'taxable_amount' => $amounts['taxable_amount'],
            'gst_amount' => $amounts['gst_amount'],
            'final_amount' => $amounts['final_amount'],
            'line_total' => $amounts['final_amount'],
            'display_summary' => sprintf(
                '%d Cases × %d Nos = %d Nos',
                $caseQuantity,
                $nosPerCase,
                $totalQuantityNos,
            ),
        ];
    }
}
