<?php

namespace App\Actions\Orders;

use App\Models\Dealer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdatePendingOrder
{
    public function __construct(
        private readonly OrderLineCalculationService $lineCalculator = new OrderLineCalculationService,
    ) {}

    /**
     * @param  array{
     *     dealer_id: int,
     *     remarks?: ?string,
     *     items: list<array<string, mixed>>
     * }  $payload
     */
    public function execute(
        Order $order,
        array $payload,
        Dealer $dealer,
        ?User $editor = null,
        ?string $editedByRole = null,
    ): Order {
        if (! $order->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ['Only orders pending approval can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($order, $payload, $dealer, $editor, $editedByRole): Order {
            $calculatedItems = $this->calculateItems($payload['items']);
            $totals = $this->summarizeItems($calculatedItems);

            $order->items()->delete();

            $attributes = [
                'dealer_id' => $dealer->id,
                'remarks' => $payload['remarks'] ?? null,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'gst_amount' => $totals['gst_amount'],
                'grand_total' => $totals['grand_total'],
            ];

            if ($editor !== null) {
                $attributes['last_edited_by'] = $editor->id;
                $attributes['last_edited_at'] = now('Asia/Kolkata');
                $attributes['last_edited_by_role'] = $editedByRole;
            }

            $order->update($attributes);
            $this->persistItems($order, $calculatedItems);

            return $order->fresh([
                'dealer:id,dealer_code,firm_name,owner_name,village,taluka,district,state,mobile,address',
                'salesEmployee:id,full_name,employee_code',
                'items.product:id,product_name,product_code,dealer_price',
                'lastEditedByUser:id,name',
            ]);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function calculateItems(array $items): array
    {
        $calculatedItems = [];

        foreach ($items as $index => $item) {
            $product = Product::query()
                ->whereKey($item['product_id'])
                ->where('status', true)
                ->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => 'Selected product is not active.',
                ]);
            }

            try {
                $calculatedItems[] = $this->lineCalculator->calculateForProduct(
                    product: $product,
                    caseQuantity: (int) $item['case_quantity'],
                    ratePerNo: (float) $item['rate_per_no'],
                    requestedDiscountPercentage: (float) $item['discount_value'],
                    requestedGstPercentage: (float) $item['gst_percentage'],
                );
            } catch (ValidationException $exception) {
                $messages = $exception->errors();
                $firstKey = array_key_first($messages);
                $firstMessage = $messages[$firstKey][0] ?? 'Invalid row data.';

                throw ValidationException::withMessages([
                    "items.$index.".($firstKey ?? 'case_quantity') => $firstMessage,
                ]);
            }
        }

        return $calculatedItems;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_amount: float, gst_amount: float, grand_total: float}
     */
    private function summarizeItems(array $items): array
    {
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalGst = 0.0;

        foreach ($items as $item) {
            $subtotal += $item['base_amount'];
            $totalDiscount += $item['discount_amount'];
            $totalGst += $item['gst_amount'];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($totalDiscount, 2),
            'gst_amount' => round($totalGst, 2),
            'grand_total' => round($subtotal - $totalDiscount + $totalGst, 2),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function persistItems(Order $order, array $items): void
    {
        foreach ($items as $item) {
            $order->items()->create([
                'product_id' => $item['product_id'],
                'case_quantity' => $item['case_quantity'],
                'nos_per_case' => $item['nos_per_case'],
                'total_quantity_nos' => $item['total_quantity_nos'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'rate_per_no' => $item['rate_per_no'],
                'rate' => $item['rate'],
                'discount_percentage' => $item['discount_percentage'],
                'discount_amount' => $item['discount_amount'],
                'gst_percentage' => $item['gst_percentage'],
                'base_amount' => $item['base_amount'],
                'taxable_amount' => $item['taxable_amount'],
                'gst_amount' => $item['gst_amount'],
                'final_amount' => $item['final_amount'],
                'line_total' => $item['line_total'],
            ]);
        }
    }
}
