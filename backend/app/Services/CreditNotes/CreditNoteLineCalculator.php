<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Models\Product;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Validation\ValidationException;

final class CreditNoteLineCalculator
{
    public function __construct(
        private readonly OrderLineCalculationService $orderLines = new OrderLineCalculationService,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function calculateItem(string $type, array $item): array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $reason = filled($item['reason'] ?? null) ? trim((string) $item['reason']) : null;

        if ($productId < 1) {
            throw ValidationException::withMessages([
                'items' => ['Each line must include a product.'],
            ]);
        }

        if ($type === CreditNote::TYPE_SALES_RETURN) {
            return $this->salesReturnItem($productId, $item, $reason);
        }

        $quantity = round((float) ($item['quantity'] ?? 0), 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be greater than 0.'],
            ]);
        }

        $originalRate = round((float) ($item['original_rate'] ?? 0), 2);
        $revisedRate = round((float) ($item['revised_rate'] ?? 0), 2);

        if ($originalRate < 0 || $revisedRate < 0) {
            throw ValidationException::withMessages([
                'items' => ['Rates cannot be negative.'],
            ]);
        }

        if (abs($originalRate - $revisedRate) < 0.01) {
            throw ValidationException::withMessages([
                'items' => ['Original rate and revised rate must be different.'],
            ]);
        }

        return [
            'product_id' => $productId,
            'case_quantity' => null,
            'nos_per_case' => null,
            'total_quantity_nos' => null,
            'quantity' => $quantity,
            'rate' => null,
            'rate_per_no' => null,
            'rate_type' => null,
            'original_rate' => $originalRate,
            'revised_rate' => $revisedRate,
            'discount_percentage' => null,
            'discount_amount' => null,
            'gst_percentage' => null,
            'base_amount' => null,
            'taxable_amount' => null,
            'gst_amount' => null,
            'final_amount' => round(abs($originalRate - $revisedRate) * $quantity, 2),
            'amount' => round(abs($originalRate - $revisedRate) * $quantity, 2),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, amount: float}
     */
    public function calculate(string $type, array $items): array
    {
        $calculated = array_map(
            fn (array $item): array => $this->calculateItem($type, $item),
            $items,
        );

        $amount = round(array_sum(array_column($calculated, 'amount')), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Credit Note amount must be greater than 0.'],
            ]);
        }

        return [
            'items' => $calculated,
            'amount' => $amount,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function salesReturnItem(int $productId, array $item, ?string $reason): array
    {
        $product = Product::query()->whereKey($productId)->where('status', true)->first();
        if ($product === null) {
            throw ValidationException::withMessages([
                'items' => ['Selected product is not active.'],
            ]);
        }

        $caseQuantity = (int) ($item['case_quantity'] ?? 0);
        if ($caseQuantity < 1) {
            throw ValidationException::withMessages([
                'items' => ['Case quantity must be at least 1.'],
            ]);
        }

        $ratePerNo = round((float) ($item['rate_per_no'] ?? $item['rate'] ?? 0), 2);
        $discount = round((float) ($item['discount_value'] ?? $item['discount_percentage'] ?? 0), 2);
        $gst = (float) ($item['gst_percentage'] ?? $product->gst_percentage ?? 0);
        $rateType = $this->orderLines->normalizeRateType($item['rate_type'] ?? null);

        $calculated = $this->orderLines->calculateForProduct(
            product: $product,
            caseQuantity: $caseQuantity,
            ratePerNo: $ratePerNo,
            requestedDiscountPercentage: $discount,
            requestedGstPercentage: $gst,
            enforceDiscountRule: true,
            rateType: $rateType,
        );

        return [
            'product_id' => $productId,
            'case_quantity' => $calculated['case_quantity'],
            'nos_per_case' => $calculated['nos_per_case'],
            'total_quantity_nos' => $calculated['total_quantity_nos'],
            'quantity' => $calculated['quantity'],
            'rate' => $calculated['rate_per_no'],
            'rate_per_no' => $calculated['rate_per_no'],
            'rate_type' => $calculated['rate_type'],
            'original_rate' => null,
            'revised_rate' => null,
            'discount_percentage' => $calculated['discount_percentage'],
            'discount_amount' => $calculated['discount_amount'],
            'gst_percentage' => $calculated['gst_percentage'],
            'base_amount' => $calculated['base_amount'],
            'taxable_amount' => $calculated['taxable_amount'],
            'gst_amount' => $calculated['gst_amount'],
            'final_amount' => $calculated['final_amount'],
            'amount' => $calculated['final_amount'],
            'reason' => $reason,
        ];
    }
}
