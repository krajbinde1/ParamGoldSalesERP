<?php

namespace App\Services\Orders;

use App\Enums\TransportChargeType;
use App\Models\Order;
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
            'final_grand_total' => round($taxableAfter + $gstAmount, 2),
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

    /**
     * @return array{subtotal: float, discount_amount: float, gst_amount: float, taxable_amount: float, grand_total: float}
     */
    public static function resolveBaseTotals(Order $order): array
    {
        if ($order->relationLoaded('items') && $order->items->isNotEmpty()) {
            $subtotal = 0.0;
            $discount = 0.0;
            $gst = 0.0;
            $lineCalculator = app(OrderLineCalculationService::class);

            foreach ($order->items as $item) {
                $amounts = $lineCalculator->resolveStoredAmounts($item);
                $subtotal += $amounts['base_amount'];
                $discount += $amounts['discount_amount'];
                $gst += $amounts['gst_amount'];
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
            'grand_total' => $calc['final_grand_total'],
        ];
    }

    public static function reapplyStoredTransport(Order $order): bool
    {
        if (! self::hasSavedAdjustment($order)) {
            return false;
        }

        $calc = self::calculateForOrder(
            $order,
            (string) $order->transport_charge_type,
            (float) ($order->transport_amount ?? 0),
            strict: false,
        );

        $order->forceFill(self::persistedAttributes($calc))->saveQuietly();

        return true;
    }

    public static function originalGrandTotal(Order $order): float
    {
        return self::resolveBaseTotals($order)['grand_total'];
    }

    public static function finalGrandTotal(Order $order): float
    {
        if (! self::hasSavedAdjustment($order)) {
            return round((float) $order->grand_total, 2);
        }

        return self::calculateForOrder(
            $order,
            (string) $order->transport_charge_type,
            (float) ($order->transport_amount ?? 0),
            strict: false,
        )['final_grand_total'];
    }

    public static function hasSavedAdjustment(Order $order): bool
    {
        return filled($order->transport_charge_type)
            && TransportChargeType::tryFrom((string) $order->transport_charge_type) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Order $order): array
    {
        $type = filled($order->transport_charge_type)
            ? TransportChargeType::tryFrom((string) $order->transport_charge_type)
            : null;
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
            'final_grand_total' => $calc['final_grand_total'] ?? round((float) $order->grand_total, 2),
        ];
    }

    public static function formatMoney(float $amount): string
    {
        $prefix = $amount < 0 ? '- ' : '';

        return $prefix.'₹'.number_format(abs($amount), 2, '.', ',');
    }

    public static function formatAdjustment(float $adjustment): string
    {
        $sign = $adjustment < 0 ? '- ' : '+ ';

        return $sign.'₹'.number_format(abs($adjustment), 2, '.', ',');
    }
}
