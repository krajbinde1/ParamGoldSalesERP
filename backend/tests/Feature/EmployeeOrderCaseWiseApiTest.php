<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Models\Dealer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function orderApiEmployee(array $overrides = []): \App\Models\Employee
{
    static $counter = 9200000000;

    $counter++;

    return app(CreateEmployeeWithUserAccount::class)
        ->execute(array_merge([
            'full_name' => 'Order API Employee '.$counter,
            'mobile' => (string) $counter,
            'email' => "order.api.{$counter}@example.com",
            'department' => 'Sales',
            'designation' => 'Sales Executive',
            'joining_date' => '2026-07-11',
            'salary' => 25000,
            'base_location' => 'Pune',
            'daily_allowance' => 300,
            'travel_allowance_type' => 'actual_expense',
            'company_card_issued' => false,
            'monthly_travel_expense_limit' => 500,
            'aadhaar_number' => str_pad((string) $counter, 12, '3', STR_PAD_LEFT),
            'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'G',
            'bank_name' => 'Test Bank',
            'account_number' => str_pad((string) $counter, 12, '4', STR_PAD_LEFT),
            'ifsc_code' => 'TEST0123456',
            'status' => true,
        ], $overrides))
        ->employee
        ->refresh();
}

function orderApiDealer(\App\Models\Employee $employee, array $overrides = []): Dealer
{
    static $mobileCounter = 9700000000;

    $mobileCounter++;

    return Dealer::query()->create(array_merge([
        'firm_name' => 'Order Dealer '.$mobileCounter,
        'owner_name' => 'Owner '.$mobileCounter,
        'mobile' => (string) $mobileCounter,
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ], $overrides));
}

function orderApiProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'ParamGold 1 Litre',
        'category' => 'General',
        'uom' => 'Litre',
        'nos_per_case' => 20,
        'gst_percentage' => 18,
        'dealer_price' => 150,
        'status' => true,
    ], $overrides));
}

function orderApiPayload(Dealer $dealer, Product $product, array $itemOverrides = [], array $overrides = []): array
{
    return array_merge([
        'dealer_id' => $dealer->id,
        'remarks' => 'Case-wise order',
        'items' => [
            array_merge([
                'product_id' => $product->id,
                'case_quantity' => 5,
                'rate_per_no' => 150,
                'discount_type' => 'percentage',
                'discount_value' => 0,
                'gst_percentage' => 18,
            ], $itemOverrides),
        ],
    ], $overrides);
}

it('creates a case-wise order through the employee api', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', orderApiPayload($dealer, $product))
        ->assertOk()
        ->assertJsonPath('grand_total', 17700);

    $item = OrderItem::query()->first();

    expect($item->case_quantity)->toBe(5)
        ->and($item->nos_per_case)->toBe(20)
        ->and($item->total_quantity_nos)->toBe(100)
        ->and((float) $item->base_amount)->toBe(15000.0)
        ->and((float) $item->final_amount)->toBe(17700.0);
});

it('recalculates totals on the backend and ignores tampered client amounts', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $payload = orderApiPayload($dealer, $product);
    $payload['items'][0]['total_quantity_nos'] = 999;

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.total_quantity_nos']);
});

it('disables discount when rate per no differs from dealer price via api', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', orderApiPayload($dealer, $product, [
            'case_quantity' => 2,
            'rate_per_no' => 140,
            'discount_value' => 10,
        ]))
        ->assertOk()
        ->assertJsonPath('grand_total', 6608);

    $item = OrderItem::query()->first();

    expect((float) $item->discount_percentage)->toBe(0.0)
        ->and((float) $item->base_amount)->toBe(5600.0);
});

it('rejects zero case quantity', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/orders', orderApiPayload($dealer, $product, [
            'case_quantity' => 0,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.case_quantity']);
});

it('updates pending orders with recalculated case-wise totals', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $this->actingAs($employee->user, 'sanctum');

    $createResponse = $this->postJson('/api/employee/orders', orderApiPayload($dealer, $product));
    $orderId = $createResponse->json('order_id');

    $this->putJson("/api/employee/orders/{$orderId}", orderApiPayload($dealer, $product, [
        'case_quantity' => 2,
    ]))
        ->assertOk()
        ->assertJsonPath('grand_total', 7080)
        ->assertJsonPath('data.total_cases', 2)
        ->assertJsonPath('data.total_quantity_nos', 40);

    expect(OrderItem::query()->count())->toBe(1)
        ->and(OrderItem::query()->first()->total_quantity_nos)->toBe(40);
});

it('returns case-wise details on order show', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct();

    $this->actingAs($employee->user, 'sanctum');

    $orderId = $this->postJson('/api/employee/orders', orderApiPayload($dealer, $product))
        ->json('order_id');

    $this->getJson("/api/employee/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.total_cases', 5)
        ->assertJsonPath('data.total_quantity_nos', 100)
        ->assertJsonPath('data.items.0.display_summary', '5 Cases × 20 Nos = 100 Nos')
        ->assertJsonPath('data.items.0.base_amount', 15000);
});

it('preserves legacy order detail totals without case-wise snapshots', function () {
    $employee = orderApiEmployee();
    $dealer = orderApiDealer($employee);
    $product = orderApiProduct(['nos_per_case' => 1]);

    $order = Order::query()->create([
        'order_no' => 'PG-LEGACY-0001',
        'order_date' => now()->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'status' => 'pending_approval',
        'payment_type' => 'Credit',
        'subtotal' => 1000,
        'discount_amount' => 0,
        'gst_amount' => 180,
        'grand_total' => 1180,
    ]);

    OrderItem::withoutEvents(function () use ($order, $product): void {
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'unit' => 'PCS',
            'rate' => 100,
            'discount_percentage' => 0,
            'gst_percentage' => 18,
            'line_total' => 1180,
        ]);
    });

    $this->actingAs($employee->user, 'sanctum')
        ->getJson("/api/employee/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.grand_total', 1180)
        ->assertJsonPath('data.items.0.total_quantity_nos', 10)
        ->assertJsonPath('data.items.0.final_amount', 1180);
});
