<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Order;
use App\Services\Orders\OrderBillingTransportCalculator;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function recalculateTotalsEmployee(string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Recalc User '.$mobile,
        'mobile' => $mobile,
        'email' => 'recalc.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Employee',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Aurangabad',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '23456789'.substr($mobile, -4),
        'pan_number' => 'ABCDE123'.substr($mobile, -1).'F',
        'bank_name' => 'Test Bank',
        'account_number' => '12345678901'.substr($mobile, -1),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

function recalculateTotalsOrderWithItem(
    int $employeeId,
    array $orderAttributes = [],
    array $itemOverrides = [],
): Order {
    $dealerId = \App\Models\Dealer::query()->create([
        'firm_name' => 'Recalc Dealer',
        'owner_name' => 'Owner',
        'mobile' => '977777'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
    ])->id;

    $product = \App\Models\Product::query()->create([
        'product_code' => 'PG-RC-'.random_int(1000, 9999),
        'product_name' => 'Recalc Product',
        'dealer_price' => 10,
        'gst_percentage' => 18,
        'uom' => 'Nos',
        'nos_per_case' => 10,
        'status' => true,
    ]);

    $order = Order::query()->create(array_merge([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'status' => Order::STATUS_DISPATCHED,
        'payment_type' => 'Credit',
        'subtotal' => 50,
        'discount_amount' => 0,
        'gst_amount' => 18,
        'grand_total' => 368,
        'transport_amount' => 250,
    ], $orderAttributes));

    $order->items()->create(array_merge([
        'product_id' => $product->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 10,
        'rate' => 10,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 100,
        'taxable_amount' => 100,
        'gst_amount' => 18,
        'final_amount' => 118,
        'line_total' => 118,
    ], $itemOverrides));

    $order->forceFill(array_merge([
        'subtotal' => 50,
        'discount_amount' => 0,
        'gst_amount' => 18,
        'grand_total' => 368,
        'transport_amount' => $order->transport_amount,
    ], $orderAttributes))->saveQuietly();

    return $order->fresh(['items']);
}

it('persists corrected subtotal gst and grand total from items for extra transport', function () {
    $employee = recalculateTotalsEmployee('9200000401');
    $order = recalculateTotalsOrderWithItem($employee->id, [
        'transport_charge_type' => 'transport_extra',
        'original_grand_total' => 118,
        'transport_adjustment' => 250,
        'status' => Order::STATUS_DISPATCHED,
    ]);

    OrderBillingTransportCalculator::persistCorrectedTotals($order);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->gst_amount)->toBe(63.0)
        ->and((float) $fresh->grand_total)->toBe(413.0)
        ->and((float) $fresh->taxable_amount_after_transport)->toBe(350.0)
        ->and((float) $fresh->transport_adjustment)->toBe(250.0);
});

it('subtracts company transport stored on the legacy transport_type column', function () {
    $employee = recalculateTotalsEmployee('9200000402');
    $order = recalculateTotalsOrderWithItem($employee->id, [
        'transport_charge_type' => null,
        'transport_type' => 'company_transport',
        'transport_amount' => 15,
        'gst_amount' => 18,
        'grand_total' => 103,
        'status' => Order::STATUS_BILLED,
    ]);

    OrderBillingTransportCalculator::persistCorrectedTotals($order);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_BILLED)
        ->and((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->gst_amount)->toBe(15.3)
        ->and((float) $fresh->grand_total)->toBe(100.3)
        ->and($fresh->transport_charge_type)->toBe('company_transport');
});

it('treats outside_transport as transport extra when persisting historical totals', function () {
    $employee = recalculateTotalsEmployee('9200000403');
    $order = recalculateTotalsOrderWithItem($employee->id, [
        'transport_charge_type' => null,
        'transport_type' => 'outside_transport',
        'transport_amount' => 250,
        'status' => Order::STATUS_APPROVED,
    ]);

    OrderBillingTransportCalculator::persistCorrectedTotals($order);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_APPROVED)
        ->and((float) $fresh->gst_amount)->toBe(63.0)
        ->and((float) $fresh->grand_total)->toBe(413.0)
        ->and($fresh->transport_charge_type)->toBe('transport_extra');
});

it('rebuilds item totals for orders without transport and leaves status unchanged', function () {
    $employee = recalculateTotalsEmployee('9200000404');
    $order = recalculateTotalsOrderWithItem($employee->id, [
        'transport_charge_type' => null,
        'transport_type' => null,
        'transport_amount' => null,
        'subtotal' => 50,
        'gst_amount' => 0,
        'grand_total' => 50,
        'status' => Order::STATUS_ON_HOLD,
    ]);

    OrderBillingTransportCalculator::persistCorrectedTotals($order);

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_ON_HOLD)
        ->and((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->gst_amount)->toBe(18.0)
        ->and((float) $fresh->grand_total)->toBe(118.0);
});

it('runs the artisan command to persist corrected totals for all orders', function () {
    $employee = recalculateTotalsEmployee('9200000405');
    $order = recalculateTotalsOrderWithItem($employee->id, [
        'transport_charge_type' => 'Company Transport',
        'transport_amount' => 15,
        'gst_amount' => 18,
        'grand_total' => 103,
        'status' => Order::STATUS_PENDING_FOR_BILLING,
    ]);

    $this->artisan('orders:recalculate-totals')
        ->assertSuccessful();

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(Order::STATUS_PENDING_FOR_BILLING)
        ->and((float) $fresh->subtotal)->toBe(100.0)
        ->and((float) $fresh->gst_amount)->toBe(15.3)
        ->and((float) $fresh->grand_total)->toBe(100.3)
        ->and($fresh->transport_charge_type)->toBe('company_transport');
});
