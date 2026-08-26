<?php

namespace App\Services\Orders;

use App\Enums\TransportType;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

final class OrderDispatchCalculationService
{
    public function __construct(
        private readonly OrderLineCalculationService $lineCalculator = new OrderLineCalculationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function calculate(Order $order, string $transportType, float $transportAmount): array
    {
        $order->loadMissing(['items.product']);

        $transportType = TransportType::from($transportType)->value;
        $transportAmount = round(max($transportAmount, 0), 2);

        $lines = [];
        $grossAmount = 0.0;
        $totalDiscount = 0.0;
        $subtotalBeforeTransport = 0.0;
        $totalCases = 0;
        $totalQuantityNos = 0;

        foreach ($order->items as $item) {
            $amounts = $this->lineCalculator->resolveStoredAmounts($item);
            $base = $amounts['base_amount'];
            $discountAmount = $amounts['discount_amount'];
            $taxableBefore = $amounts['taxable_amount'];
            $caseQuantity = (int) ($item->case_quantity ?? 1);
            $nosPerCase = (int) ($item->nos_per_case ?? 1);
            $totalItemNos = (int) ($item->total_quantity_nos ?? round((float) $item->quantity));

            $lines[] = [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->product_name,
                'product_code' => $item->product?->product_code,
                'case_quantity' => $caseQuantity,
                'nos_per_case' => $nosPerCase,
                'total_quantity_nos' => $totalItemNos,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'rate_per_no' => (float) ($item->rate_per_no ?? $item->rate),
                'rate' => (float) $item->rate,
                'discount_percentage' => (float) $item->discount_percentage,
                'discount_amount' => $discountAmount,
                'taxable_before_transport' => $taxableBefore,
                'gst_percentage' => (float) $item->gst_percentage,
                'display_summary' => sprintf(
                    '%d Cases × %d Nos = %d Nos',
                    $caseQuantity,
                    $nosPerCase,
                    $totalItemNos,
                ),
            ];

            $grossAmount += $base;
            $totalDiscount += $discountAmount;
            $subtotalBeforeTransport += $taxableBefore;
            $totalCases += $caseQuantity;
            $totalQuantityNos += $totalItemNos;
        }

        $grossAmount = round($grossAmount, 2);
        $totalDiscount = round($totalDiscount, 2);
        $subtotalBeforeTransport = round($subtotalBeforeTransport, 2);

        if ($transportAmount > $subtotalBeforeTransport) {
            throw ValidationException::withMessages([
                'transport_amount' => 'Transport amount cannot exceed the subtotal before transport.',
            ]);
        }

        $allocatedTransport = 0.0;
        $totalGst = 0.0;
        $grandTotal = 0.0;
        $lineCount = count($lines);

        foreach ($lines as $index => &$line) {
            $isLast = $index === $lineCount - 1;
            $share = $subtotalBeforeTransport > 0
                ? $line['taxable_before_transport'] / $subtotalBeforeTransport
                : 0.0;

            $transportShare = $isLast
                ? round($transportAmount - $allocatedTransport, 2)
                : round($transportAmount * $share, 2);
            $allocatedTransport += $transportShare;

            $taxableAfter = round($line['taxable_before_transport'] - $transportShare, 2);
            $gstAmount = round($taxableAfter * ($line['gst_percentage'] / 100), 2);
            $lineTotal = round($taxableAfter + $gstAmount, 2);

            $line['transport_share'] = $transportShare;
            $line['taxable_after_transport'] = $taxableAfter;
            $line['gst_amount'] = $gstAmount;
            $line['line_total'] = $lineTotal;
            $line['final_amount'] = $lineTotal;

            $totalGst += $gstAmount;
            $grandTotal += $lineTotal;
        }
        unset($line);

        $taxableAfterTransport = round($subtotalBeforeTransport - $transportAmount, 2);
        $totalGst = round($totalGst, 2);
        $round = OrderBillingTransportCalculator::roundOffGrandTotal(round($grandTotal, 2));

        return [
            'gross_amount' => $grossAmount,
            'total_discount' => $totalDiscount,
            'subtotal_before_transport' => $subtotalBeforeTransport,
            'total_cases' => $totalCases,
            'total_quantity_nos' => $totalQuantityNos,
            'transport_type' => $transportType,
            'transport_type_label' => TransportType::from($transportType)->label(),
            'transport_amount' => $transportAmount,
            'taxable_amount_after_transport' => $taxableAfterTransport,
            'total_gst' => $totalGst,
            'unrounded_grand_total' => $round['unrounded_grand_total'],
            'round_off' => $round['round_off'],
            'grand_total' => $round['rounded_grand_total'],
            'items' => array_values($lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateForOrder(Order $order): array
    {
        if ($order->status !== Order::STATUS_DISPATCHED || blank($order->transport_type)) {
            return $this->calculate($order, TransportType::CompanyTransport->value, 0);
        }

        return $this->calculate(
            $order,
            (string) $order->transport_type,
            (float) $order->transport_amount,
        );
    }
}
