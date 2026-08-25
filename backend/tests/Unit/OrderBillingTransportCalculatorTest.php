<?php

use App\Enums\TransportChargeType;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('adds transport extra to subtotal then recalculates gst and grand total', function () {
    $calc = OrderBillingTransportCalculator::calculate(
        subtotal: 100,
        discountAmount: 0,
        originalGst: 18,
        chargeType: TransportChargeType::TransportExtra->value,
        transportCharges: 250,
    );

    expect($calc['transport_adjustment'])->toBe(250.0)
        ->and($calc['taxable_before_transport'])->toBe(100.0)
        ->and($calc['taxable_amount_after_transport'])->toBe(350.0)
        ->and($calc['gst_amount'])->toBe(63.0)
        ->and($calc['original_grand_total'])->toBe(118.0)
        ->and($calc['final_grand_total'])->toBe(413.0);
});

it('subtracts company transport from subtotal then recalculates gst and grand total', function () {
    $calc = OrderBillingTransportCalculator::calculate(
        subtotal: 100,
        discountAmount: 0,
        originalGst: 18,
        chargeType: TransportChargeType::CompanyTransport->value,
        transportCharges: 15,
    );

    expect($calc['transport_adjustment'])->toBe(-15.0)
        ->and($calc['taxable_amount_after_transport'])->toBe(85.0)
        ->and($calc['gst_amount'])->toBe(15.3)
        ->and($calc['original_grand_total'])->toBe(118.0)
        ->and($calc['final_grand_total'])->toBe(100.3);
});

it('keeps gst unchanged when transport charges are zero', function () {
    $calc = OrderBillingTransportCalculator::calculate(
        subtotal: 100,
        discountAmount: 10,
        originalGst: 16.2,
        chargeType: TransportChargeType::TransportExtra->value,
        transportCharges: 0,
    );

    expect($calc['transport_adjustment'])->toBe(0.0)
        ->and($calc['taxable_amount_after_transport'])->toBe(90.0)
        ->and($calc['gst_amount'])->toBe(16.2)
        ->and($calc['final_grand_total'])->toBe(106.2);
});

it('applies transport after discount so gst follows the adjusted taxable value', function () {
    $calc = OrderBillingTransportCalculator::calculate(
        subtotal: 200,
        discountAmount: 20,
        originalGst: 32.4,
        chargeType: TransportChargeType::TransportExtra->value,
        transportCharges: 50,
    );

    expect($calc['taxable_before_transport'])->toBe(180.0)
        ->and($calc['taxable_amount_after_transport'])->toBe(230.0)
        ->and($calc['gst_amount'])->toBe(41.4)
        ->and($calc['final_grand_total'])->toBe(271.4);
});

it('rejects company transport above the taxable value', function () {
    expect(fn () => OrderBillingTransportCalculator::calculate(
        subtotal: 100,
        discountAmount: 0,
        originalGst: 18,
        chargeType: TransportChargeType::CompanyTransport->value,
        transportCharges: 110,
    ))->toThrow(ValidationException::class);
});

it('caps historical company transport that exceeds taxable value when not strict', function () {
    $calc = OrderBillingTransportCalculator::calculate(
        subtotal: 100,
        discountAmount: 0,
        originalGst: 18,
        chargeType: TransportChargeType::CompanyTransport->value,
        transportCharges: 110,
        strict: false,
    );

    expect($calc['taxable_amount_after_transport'])->toBe(0.0)
        ->and($calc['gst_amount'])->toBe(0.0)
        ->and($calc['final_grand_total'])->toBe(0.0);
});
