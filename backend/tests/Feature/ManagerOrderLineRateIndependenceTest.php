<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Orders\OrderLineCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rateIndependenceEmployee(UserRole $role, string $mobile): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' Rate '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.rate@example.com',
        'department' => 'Sales',
        'designation' => $role->label(),
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
        'role' => $role->value,
    ])->employee;
}

function rateIndependenceProduct(string $name, float $price): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'category' => 'General',
        'uom' => 'Nos',
        'nos_per_case' => 10,
        'gst_percentage' => 18,
        'dealer_price' => $price,
        'status' => true,
    ]);
}

it('does not replace a submitted custom rate with the price list rate', function (): void {
    $product = rateIndependenceProduct('Fixed Pack', 80);
    $line = app(OrderLineCalculationService::class)->calculateForProduct(
        product: $product,
        caseQuantity: 1,
        ratePerNo: 55,
        requestedDiscountPercentage: 10,
        requestedGstPercentage: 18,
        rateType: OrderLineCalculationService::RATE_TYPE_PRICE_LIST,
    );

    expect($line['rate_type'])->toBe(OrderLineCalculationService::RATE_TYPE_FIXED)
        ->and((float) $line['rate_per_no'])->toBe(55.0)
        ->and((float) $line['discount_percentage'])->toBe(0.0)
        ->and((float) $line['base_amount'])->toBe(550.0);
});

it('keeps an unedited fixed-rate line when manager updates another product', function (): void {
    $manager = rateIndependenceEmployee(UserRole::Manager, '9210000101');
    $employee = rateIndependenceEmployee(UserRole::Employee, '9210000102');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $dealer = Dealer::query()->create([
        'firm_name' => 'Rate Independence Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9765432101',
        'address' => 'Rate Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Rate Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $fixedProduct = rateIndependenceProduct('Product A Fixed', 100);
    $listProduct = rateIndependenceProduct('Product B List', 40);

    $order = Order::query()->create([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 0,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 0,
    ]);

    $order->items()->create([
        'product_id' => $fixedProduct->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 70,
        'rate_type' => OrderLineCalculationService::RATE_TYPE_FIXED,
        'rate' => 70,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 700,
        'taxable_amount' => 700,
        'gst_amount' => 126,
        'final_amount' => 826,
        'line_total' => 826,
    ]);
    $order->items()->create([
        'product_id' => $listProduct->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 40,
        'rate_type' => OrderLineCalculationService::RATE_TYPE_PRICE_LIST,
        'rate' => 40,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 400,
        'taxable_amount' => 400,
        'gst_amount' => 72,
        'final_amount' => 472,
        'line_total' => 472,
    ]);

    $this->actingAs($manager->user, 'sanctum')
        ->getJson("/api/manager/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.line_items.0.rate_type', 'fixed_rate')
        ->assertJsonPath('data.line_items.0.rate_per_no', 70)
        ->assertJsonPath('data.line_items.1.rate_type', 'price_list')
        ->assertJsonPath('data.line_items.1.rate_per_no', 40);

    $this->actingAs($manager->user, 'sanctum')
        ->putJson("/api/manager/orders/{$order->id}", [
            'dealer_id' => $dealer->id,
            'remarks' => 'Changed product B only',
            'items' => [
                [
                    'product_id' => $fixedProduct->id,
                    'case_quantity' => 1,
                    'rate_per_no' => 70,
                    'rate_type' => 'fixed_rate',
                    'discount_type' => 'percentage',
                    'discount_value' => 0,
                    'gst_percentage' => 18,
                ],
                [
                    'product_id' => $listProduct->id,
                    'case_quantity' => 1,
                    'rate_per_no' => 36,
                    'rate_type' => 'fixed_rate',
                    'discount_type' => 'percentage',
                    'discount_value' => 0,
                    'gst_percentage' => 18,
                ],
            ],
        ])
        ->assertOk();

    $items = $order->fresh()->items()->orderBy('id')->get();

    expect((float) $items[0]->rate_per_no)->toBe(70.0)
        ->and($items[0]->rate_type)->toBe(OrderLineCalculationService::RATE_TYPE_FIXED)
        ->and((float) $items[0]->discount_percentage)->toBe(0.0)
        ->and((float) $items[1]->rate_per_no)->toBe(36.0)
        ->and($items[1]->rate_type)->toBe(OrderLineCalculationService::RATE_TYPE_FIXED);
});

it('preserves a legacy custom rate wrongly stored as price list when manager saves another line', function (): void {
    $manager = rateIndependenceEmployee(UserRole::Manager, '9210000103');
    $employee = rateIndependenceEmployee(UserRole::Employee, '9210000104');
    $employee->update(['reporting_manager_id' => $manager->id]);

    $dealer = Dealer::query()->create([
        'firm_name' => 'Legacy Rate Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9765432102',
        'address' => 'Legacy Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Legacy Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $fixedProduct = rateIndependenceProduct('Legacy Fixed', 100);
    $listProduct = rateIndependenceProduct('Legacy List', 40);

    $order = Order::query()->create([
        'order_no' => 'ORD'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 0,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 0,
    ]);

    $order->items()->create([
        'product_id' => $fixedProduct->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 70,
        'rate_type' => OrderLineCalculationService::RATE_TYPE_PRICE_LIST,
        'rate' => 70,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 700,
        'taxable_amount' => 700,
        'gst_amount' => 126,
        'final_amount' => 826,
        'line_total' => 826,
    ]);
    $order->items()->create([
        'product_id' => $listProduct->id,
        'case_quantity' => 1,
        'nos_per_case' => 10,
        'total_quantity_nos' => 10,
        'quantity' => 10,
        'unit' => 'Nos',
        'rate_per_no' => 40,
        'rate_type' => OrderLineCalculationService::RATE_TYPE_PRICE_LIST,
        'rate' => 40,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 400,
        'taxable_amount' => 400,
        'gst_amount' => 72,
        'final_amount' => 472,
        'line_total' => 472,
    ]);

    $this->actingAs($manager->user, 'sanctum')
        ->putJson("/api/manager/orders/{$order->id}", [
            'dealer_id' => $dealer->id,
            'items' => [
                [
                    'product_id' => $fixedProduct->id,
                    'case_quantity' => 1,
                    'rate_per_no' => 70,
                    'rate_type' => 'price_list',
                    'discount_type' => 'percentage',
                    'discount_value' => 0,
                    'gst_percentage' => 18,
                ],
                [
                    'product_id' => $listProduct->id,
                    'case_quantity' => 1,
                    'rate_per_no' => 36,
                    'rate_type' => 'price_list',
                    'discount_type' => 'percentage',
                    'discount_value' => 0,
                    'gst_percentage' => 18,
                ],
            ],
        ])
        ->assertOk();

    $items = $order->fresh()->items()->orderBy('id')->get();

    expect((float) $items[0]->rate_per_no)->toBe(70.0)
        ->and($items[0]->rate_type)->toBe(OrderLineCalculationService::RATE_TYPE_FIXED)
        ->and((float) $items[1]->rate_per_no)->toBe(36.0)
        ->and($items[1]->rate_type)->toBe(OrderLineCalculationService::RATE_TYPE_FIXED);
});
