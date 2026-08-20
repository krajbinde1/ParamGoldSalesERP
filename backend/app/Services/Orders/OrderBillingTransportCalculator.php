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
     *     original_grand_total: float,
     *     transport_adjustment: float,
     *     final_grand_total: float
     * }
     */
    public static function calculate(float $originalGrandTotal, string $chargeType, float $transportCharges): array
    {
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

        $original = round($originalGrandTotal, 2);
        $charges = round($transportCharges, 2);
        $adjustment = 0.0;

        if ($charges > 0) {
            if ($type === TransportChargeType::CompanyTransport && $charges > $original) {
                throw ValidationException::withMessages([
                    'transport_freight' => ['Company Transport charges cannot exceed the original grand total.'],
                ]);
            }

            $adjustment = round($charges * $type->adjustmentSign(), 2);
        }

        return [
            'transport_charge_type' => $type->value,
            'transport_amount' => $charges,
            'original_grand_total' => $original,
            'transport_adjustment' => $adjustment,
            'final_grand_total' => round($original + $adjustment, 2),
        ];
    }

    public static function originalGrandTotal(Order $order): float
    {
        if ($order->original_grand_total !== null) {
            return round((float) $order->original_grand_total, 2);
        }

        return round((float) $order->grand_total, 2);
    }

    public static function finalGrandTotal(Order $order): float
    {
        if ($order->original_grand_total !== null && $order->transport_adjustment !== null) {
            return round((float) $order->original_grand_total + (float) $order->transport_adjustment, 2);
        }

        return round((float) $order->grand_total, 2);
    }

    public static function hasSavedAdjustment(Order $order): bool
    {
        return filled($order->transport_charge_type) && $order->original_grand_total !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Order $order): array
    {
        $type = filled($order->transport_charge_type)
            ? TransportChargeType::tryFrom((string) $order->transport_charge_type)
            : null;
        $original = $order->original_grand_total !== null
            ? round((float) $order->original_grand_total, 2)
            : null;
        $adjustment = $order->transport_adjustment !== null
            ? round((float) $order->transport_adjustment, 2)
            : null;
        $charges = $order->transport_amount !== null
            ? round((float) $order->transport_amount, 2)
            : null;

        return [
            'vehicle_no' => $order->vehicle_number,
            'vehicle_number' => $order->vehicle_number,
            'transport_charge_type' => $order->transport_charge_type,
            'transport_charge_type_label' => $type?->label(),
            'transport_charges' => $charges,
            'transport_amount' => $charges,
            'transport_freight' => $charges,
            'original_grand_total' => $original,
            'transport_adjustment' => $adjustment,
            'final_grand_total' => self::finalGrandTotal($order),
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
