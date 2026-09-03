<?php

namespace App\Services\Orders;

use App\Enums\TransportChargeType;
use App\Models\Order;
use App\Services\Dealers\DealerLedgerPostingService;
use Illuminate\Validation\ValidationException;

final class OrderBillingTransportCalculator
{
    /**
     * @return array{
     *     transport_charge_type: string,
     *     transport_amount: float,
     *     transport_adjustment: float,
     *     subtotal: float,
     *     discount_amount: float,
     *     taxable_before_transport: float,
     *     taxable_amount_after_transport: float,
     *     original_gst_amount: float,
     *     gst_amount: float,
     *     cgst_amount: float,
     *     sgst_amount: float,
     *     original_grand_total: float,
     *     unrounded_grand_total: float,
     *     round_off: float,
     *     final_grand_total: float
     * }
     */
    public static function calculate(
        float $subtotal,
        float $discountAmount,
        float $originalGst,
        string $chargeType,
        float $transportCharges,
        bool $strict = true,
    ): array {
        $type = TransportChargeType::tryFrom($chargeType);

        if ($type === null) {
            throw ValidationException::withMessages([
                'transport_charge_type' => ['Select Company Transport or Transport Charges Extra.'],
            ]);
        }

        if ($transportCharges < 0) {
            throw ValidationException::withMessages([
                'transport_freight' => ['Transport charges must not be negative.'],
            ]);
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discountAmount, 2);
        $originalGst = round($originalGst, 2);
        $charges = round($transportCharges, 2);
        $taxableBefore = round($subtotal - $discount, 2);
        $originalGrand = round($taxableBefore + $originalGst, 2);
        $rate = $taxableBefore > 0 ? ($originalGst / $taxableBefore) : 0.0;
        $adjustment = 0.0;

        if ($charges > 0) {
            if ($type === TransportChargeType::CompanyTransport && $charges > $taxableBefore) {
                if ($strict) {
                    throw ValidationException::withMessages([
                        'transport_freight' => ['Company Transport charges cannot exceed the taxable value (Subtotal − Discount).'],
                    ]);
                }

                $charges = $taxableBefore;
            }

            $adjustment = round($charges * $type->adjustmentSign(), 2);
        }

        $taxableAfter = round($taxableBefore + $adjustment, 2);
        if ($taxableAfter < 0) {
            $taxableAfter = 0.0;
        }

        $gstAmount = round($taxableAfter * $rate, 2);
        $cgst = round($gstAmount / 2, 2);
        $sgst = round($gstAmount - $cgst, 2);

        return [
            'transport_charge_type' => $type->value,
            'transport_amount' => round($transportCharges, 2),
            'transport_adjustment' => $adjustment,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'taxable_before_transport' => $taxableBefore,
            'taxable_amount_after_transport' => $taxableAfter,
            'original_gst_amount' => $originalGst,
            'gst_amount' => $gstAmount,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'original_grand_total' => $originalGrand,
            ...self::roundedGrandTotalFields(round($taxableAfter + $gstAmount, 2)),
        ];
    }

    /**
     * @return array{
     *     transport_charge_type: string,
     *     transport_amount: float,
     *     transport_adjustment: float,
     *     subtotal: float,
     *     discount_amount: float,
     *     taxable_before_transport: float,
     *     taxable_amount_after_transport: float,
     *     original_gst_amount: float,
     *     gst_amount: float,
     *     cgst_amount: float,
     *     sgst_amount: float,
     *     original_grand_total: float,
     *     unrounded_grand_total: float,
     *     round_off: float,
     *     final_grand_total: float
     * }
     */
    public static function calculateForOrder(
        Order $order,
        string $chargeType,
        float $transportCharges,
        bool $strict = true,
    ): array {
        $base = self::resolveBaseTotals($order);

        return self::calculate(
            subtotal: $base['subtotal'],
            discountAmount: $base['discount_amount'],
            originalGst: $base['gst_amount'],
            chargeType: $chargeType,
            transportCharges: $transportCharges,
            strict: $strict,
        );
    }

    public static function resolveChargeType(Order $order): ?TransportChargeType
    {
        $fromChargeType = TransportChargeType::tryNormalize(
            filled($order->transport_charge_type) ? (string) $order->transport_charge_type : null
        );
        if ($fromChargeType !== null) {
            return $fromChargeType;
        }

        return TransportChargeType::tryNormalize(
            filled($order->transport_type) ? (string) $order->transport_type : null
        );
    }

    /**
     * Recalculate and persist header totals from saved items + stored transport.
     * Does not change status or any workflow fields.
     *
     * @return array<string, mixed>
     */
    public static function persistCorrectedTotals(Order $order): array
    {
        $fill = self::correctedAttributes($order);
        $order->forceFill($fill)->saveQuietly();
        app(DealerLedgerPostingService::class)->syncDispatchedOrder($order);

        return $fill;
    }

    /**
     * @return array<string, mixed>
     */
    public static function correctedAttributes(Order $order): array
    {
        $order->loadMissing('items');
        $base = self::resolveBaseTotals($order);
        $type = self::resolveChargeType($order);
        $charges = round((float) ($order->transport_amount ?? 0), 2);

        $fill = [
            'subtotal' => $base['subtotal'],
            'discount_amount' => $base['discount_amount'],
            'gst_amount' => $base['gst_amount'],
            'subtotal_before_transport' => $base['taxable_amount'],
            'taxable_amount_after_transport' => $base['taxable_amount'],
            ...self::persistableRoundedTotals($base['grand_total']),
        ];

        if ($type !== null) {
            $calc = self::calculate(
                subtotal: $base['subtotal'],
                discountAmount: $base['discount_amount'],
                originalGst: $base['gst_amount'],
                chargeType: $type->value,
                transportCharges: $charges,
                strict: false,
            );
            $fill = array_merge($fill, self::persistedAttributes($calc));
            $fill['subtotal'] = $base['subtotal'];
            $fill['discount_amount'] = $base['discount_amount'];
        }

        return self::onlyExistingColumns($fill);
    }

    /**
     * @return array{updated: int}
     */
    public static function persistCorrectedTotalsForAllOrders(): array
    {
        $updated = 0;

        Order::query()
            ->with('items')
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (&$updated): void {
                foreach ($orders as $order) {
                    self::persistCorrectedTotals($order);
                    $updated++;
                }
            });

        return ['updated' => $updated];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function onlyExistingColumns(array $attributes): array
    {
        static $columns = null;
        $columns ??= array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('orders'));

        return array_filter(
            $attributes,
            fn (string $key): bool => isset($columns[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array{subtotal: float, discount_amount: float, gst_amount: float, taxable_amount: float, grand_total: float}
     */
    public static function resolveBaseTotals(Order $order): array
    {
        $order->loadMissing('items');

        if ($order->items->isNotEmpty()) {
            $subtotal = 0.0;
            $discount = 0.0;
            $gst = 0.0;
            $lineCalculator = app(OrderLineCalculationService::class);

            foreach ($order->items as $item) {
                $amounts = $lineCalculator->resolveStoredAmounts($item);
                $subtotal += $amounts['base_amount'];
                $discount += $amounts['discount_amount'];
                $gstPercentage = (float) ($item->gst_percentage ?? 0);
                if ($gstPercentage > 0) {
                    $gst += round($amounts['taxable_amount'] * ($gstPercentage / 100), 2);
                } else {
                    $gst += $amounts['gst_amount'];
                }
            }

            $subtotal = round($subtotal, 2);
            $discount = round($discount, 2);
            $gst = round($gst, 2);
            $taxable = round($subtotal - $discount, 2);

            return [
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'gst_amount' => $gst,
                'taxable_amount' => $taxable,
                'grand_total' => round($taxable + $gst, 2),
            ];
        }

        $subtotal = round((float) $order->subtotal, 2);
        $discount = round((float) $order->discount_amount, 2);
        $taxable = round($subtotal - $discount, 2);

        if ($order->original_grand_total !== null) {
            $gst = round((float) $order->original_grand_total - $taxable, 2);
            if ($gst < 0) {
                $gst = 0.0;
            }
        } else {
            $gst = round((float) $order->gst_amount, 2);
        }

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'gst_amount' => $gst,
            'taxable_amount' => $taxable,
            'grand_total' => round($taxable + $gst, 2),
        ];
    }

    /**
     * @return array<string, float|string>
     */
    public static function persistedAttributes(array $calc): array
    {
        return [
            'transport_charge_type' => $calc['transport_charge_type'],
            'transport_amount' => $calc['transport_amount'],
            'original_grand_total' => $calc['original_grand_total'],
            'transport_adjustment' => $calc['transport_adjustment'],
            'subtotal_before_transport' => $calc['taxable_before_transport'],
            'taxable_amount_after_transport' => $calc['taxable_amount_after_transport'],
            'gst_amount' => $calc['gst_amount'],
            'unrounded_grand_total' => $calc['unrounded_grand_total'],
            'round_off' => $calc['round_off'],
            'grand_total' => $calc['final_grand_total'],
        ];
    }

    public static function reapplyStoredTransport(Order $order): bool
    {
        self::persistCorrectedTotals($order);

        return true;
    }

    public static function originalGrandTotal(Order $order): float
    {
        return self::resolveBaseTotals($order)['grand_total'];
    }

    public static function finalGrandTotal(Order $order): float
    {
        $exact = $order->unrounded_grand_total !== null
            ? (float) $order->unrounded_grand_total
            : (float) $order->grand_total;

        return self::roundOffGrandTotal($exact)['rounded_grand_total'];
    }

    public static function hasSavedAdjustment(Order $order): bool
    {
        return self::resolveChargeType($order) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Order $order): array
    {
        $type = self::resolveChargeType($order);
        $charges = $order->transport_amount !== null
            ? round((float) $order->transport_amount, 2)
            : null;
        $base = self::resolveBaseTotals($order);

        $calc = null;
        if ($type !== null) {
            $calc = self::calculate(
                subtotal: $base['subtotal'],
                discountAmount: $base['discount_amount'],
                originalGst: $base['gst_amount'],
                chargeType: $type->value,
                transportCharges: (float) ($order->transport_amount ?? 0),
                strict: false,
            );
        }

        $gstAmount = $calc['gst_amount'] ?? $base['gst_amount'];
        $cgst = round($gstAmount / 2, 2);
        $sgst = round($gstAmount - $cgst, 2);
        $taxable = $calc['taxable_amount_after_transport'] ?? $base['taxable_amount'];
        $adjustment = $calc['transport_adjustment'] ?? (
            $order->transport_adjustment !== null
                ? round((float) $order->transport_adjustment, 2)
                : null
        );
        $round = $calc !== null
            ? [
                'unrounded_grand_total' => $calc['unrounded_grand_total'],
                'round_off' => $calc['round_off'],
                'final_grand_total' => $calc['final_grand_total'],
            ]
            : self::roundOffGrandTotal($base['grand_total']);

        return [
            'vehicle_no' => $order->vehicle_number,
            'vehicle_number' => $order->vehicle_number,
            'transport_charge_type' => $order->transport_charge_type,
            'transport_charge_type_label' => $type?->label(),
            'transport_charges' => $charges,
            'transport_amount' => $charges,
            'transport_freight' => $charges,
            'subtotal' => $base['subtotal'],
            'discount_amount' => $base['discount_amount'],
            'taxable_before_transport' => $base['taxable_amount'],
            'taxable_amount_after_transport' => $taxable,
            'original_gst_amount' => $base['gst_amount'],
            'gst_amount' => $gstAmount,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'original_grand_total' => $calc['original_grand_total'] ?? (
                $order->original_grand_total !== null
                    ? round((float) $order->original_grand_total, 2)
                    : null
            ),
            'transport_adjustment' => $adjustment,
            'unrounded_grand_total' => $round['unrounded_grand_total'],
            'round_off' => $round['round_off'],
            'final_grand_total' => $round['final_grand_total'] ?? $round['rounded_grand_total'],
        ];
    }

    /**
     * Round only the final Grand Total: < 0.50 down, >= 0.50 up, to a whole rupee.
     *
     * @return array{unrounded_grand_total: float, round_off: float, rounded_grand_total: float, final_grand_total: float}
     */
    public static function roundOffGrandTotal(float $exact): array
    {
        $unrounded = round($exact, 2);
        $paise = (int) round($unrounded * 100);
        $remainder = abs($paise) % 100;
        $rupees = intdiv($paise, 100);

        if ($remainder >= 50) {
            $rupees += $paise >= 0 ? 1 : -1;
        }

        $rounded = (float) $rupees;
        $roundOff = round($rounded - $unrounded, 2);

        return [
            'unrounded_grand_total' => $unrounded,
            'round_off' => $roundOff,
            'rounded_grand_total' => $rounded,
            'final_grand_total' => $rounded,
        ];
    }

    /**
     * @return array{unrounded_grand_total: float, round_off: float, grand_total: float}
     */
    public static function persistableRoundedTotals(float $exact): array
    {
        $round = self::roundOffGrandTotal($exact);

        return [
            'unrounded_grand_total' => $round['unrounded_grand_total'],
            'round_off' => $round['round_off'],
            'grand_total' => $round['rounded_grand_total'],
        ];
    }

    /**
     * @return array{unrounded_grand_total: float, round_off: float, final_grand_total: float}
     */
    private static function roundedGrandTotalFields(float $exact): array
    {
        $round = self::roundOffGrandTotal($exact);

        return [
            'unrounded_grand_total' => $round['unrounded_grand_total'],
            'round_off' => $round['round_off'],
            'final_grand_total' => $round['rounded_grand_total'],
        ];
    }

    public static function formatMoney(float $amount): string
    {
        $prefix = $amount < 0 ? '- ' : '';

        return $prefix.'₹'.number_format(abs($amount), 2, '.', ',');
    }

    public static function formatRoundOff(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '+';

        return $sign.'₹'.number_format(abs($amount), 2, '.', ',');
    }

    public static function formatAdjustment(float $adjustment): string
    {
        $sign = $adjustment < 0 ? '- ' : '+ ';

        return $sign.'₹'.number_format(abs($adjustment), 2, '.', ',');
    }
}
