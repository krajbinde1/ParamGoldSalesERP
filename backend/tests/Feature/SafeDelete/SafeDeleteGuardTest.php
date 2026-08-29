<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Actions\Employees\DeleteEmployeeWithUserAccount;
use App\Enums\BomItemType;
use App\Enums\BomOutputType;
use App\Enums\BomStatus;
use App\Enums\RawMaterialInwardStatus;
use App\Models\Attendance;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\EmployeeRoutePoint;
use App\Models\Order;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\RawMaterialInward;
use App\Models\SemiFinishedMaterial;
use App\Models\Supplier;
use App\Services\SafeDelete\SafeDeleteBlockedException;
use App\Services\SafeDelete\SafeDeleteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function safeDeleteEmployee(array $overrides = []): Employee
{
    static $counter = 9700000000;
    $counter++;

    return app(CreateEmployeeWithUserAccount::class)->execute(array_merge([
        'full_name' => 'Safe Delete Emp '.$counter,
        'mobile' => (string) $counter,
        'email' => "safe.delete.{$counter}@example.com",
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 300,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '2'.str_pad((string) ($counter % 100000000000), 11, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) ($counter % 10000), 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) $counter, 12, '2', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ], $overrides))->employee;
}

it('allows deleting an unused dealer', function () {
    $employee = safeDeleteEmployee();

    $dealer = Dealer::query()->create([
        'firm_name' => 'Unused Dealer',
        'mobile' => '9876501001',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'assigned_employee_id' => $employee->id,
        'status' => true,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($dealer))->toBeTrue();

    $dealer->delete();

    expect(Dealer::query()->find($dealer->id))->toBeNull()
        ->and(Dealer::withTrashed()->find($dealer->id))->not->toBeNull();
});

it('blocks deleting a dealer that has orders and suggests deactivate', function () {
    $employee = safeDeleteEmployee();

    $dealer = Dealer::query()->create([
        'firm_name' => 'Used Dealer',
        'mobile' => '9876501002',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'assigned_employee_id' => $employee->id,
        'status' => true,
    ]);

    Order::query()->create([
        'order_no' => 'ORD-SAFE-1',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'payment_type' => 'credit',
        'status' => 'pending_approval',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);

    $assessment = app(SafeDeleteGuard::class)->assess($dealer);

    expect($assessment->allowed)->toBeFalse()
        ->and($assessment->supportsDeactivate)->toBeTrue()
        ->and($assessment->shortMessage())->toContain('order');

    expect(fn () => $dealer->delete())->toThrow(SafeDeleteBlockedException::class);

    app(SafeDeleteGuard::class)->deactivate($dealer);

    expect($dealer->fresh()->status)->toBeFalse()
        ->and(Order::query()->where('dealer_id', $dealer->id)->exists())->toBeTrue();
});

it('blocks deleting an employee with attendance or route points', function () {
    $employee = safeDeleteEmployee();

    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => now('Asia/Kolkata')->toDateString(),
        'punch_in_time' => '09:00:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);

    EmployeeRoutePoint::query()->create([
        'attendance_id' => $attendance->id,
        'employee_id' => $employee->id,
        'local_uuid' => (string) Str::uuid(),
        'latitude' => 18.52,
        'longitude' => 73.85,
        'accuracy' => 10,
        'recorded_at' => now('Asia/Kolkata'),
        'source' => 'test',
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($employee))->toBeFalse();
    expect(fn () => $employee->delete())->toThrow(SafeDeleteBlockedException::class);

    $assessment = app(SafeDeleteGuard::class)->assess($employee);
    expect($assessment->shortMessage())->toBe(
        'Cannot delete: 1 attendance record and 1 route point are linked to this employee.'
    );
});

it('allows deleting an unused employee', function () {
    $employee = safeDeleteEmployee();

    expect(app(SafeDeleteGuard::class)->canDelete($employee))->toBeTrue();

    app(DeleteEmployeeWithUserAccount::class)->execute($employee);

    expect(Employee::query()->find($employee->id))->toBeNull();
});

it('blocks deleting a product used in orders and allows unused products', function () {
    $unused = Product::query()->create([
        'product_name' => 'Unused Product',
        'gst_percentage' => 18,
        'mrp' => 100,
        'distributor_price' => 80,
        'dealer_price' => 90,
        'retail_price' => 95,
        'status' => true,
    ]);

    $used = Product::query()->create([
        'product_name' => 'Used Product',
        'gst_percentage' => 18,
        'mrp' => 100,
        'distributor_price' => 80,
        'dealer_price' => 90,
        'retail_price' => 95,
        'status' => true,
    ]);

    $employee = safeDeleteEmployee();
    $dealer = Dealer::query()->create([
        'firm_name' => 'Order Dealer',
        'mobile' => '9876501003',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'assigned_employee_id' => $employee->id,
        'status' => true,
    ]);

    $order = Order::query()->create([
        'order_no' => 'ORD-SAFE-2',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'payment_type' => 'credit',
        'status' => 'pending_approval',
        'subtotal' => 90,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 90,
    ]);

    $order->items()->create([
        'product_id' => $used->id,
        'quantity' => 1,
        'unit' => 'Nos',
        'rate' => 90,
        'discount_percentage' => 0,
        'discount_amount' => 0,
        'gst_percentage' => 18,
        'base_amount' => 90,
        'taxable_amount' => 90,
        'gst_amount' => 0,
        'final_amount' => 90,
        'line_total' => 90,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($unused))->toBeTrue()
        ->and(app(SafeDeleteGuard::class)->canDelete($used))->toBeFalse();

    expect(fn () => $used->delete())->toThrow(SafeDeleteBlockedException::class);

    $unused->delete();
    expect(Product::query()->find($unused->id))->toBeNull();
});

it('blocks deleting a raw material used in BOM components', function () {
    $material = RawMaterial::query()->create([
        'material_name' => 'Linked RM',
        'unit' => 'Kg',
        'status' => true,
    ]);

    $product = Product::query()->create([
        'product_name' => 'BOM Product',
        'gst_percentage' => 18,
        'mrp' => 100,
        'distributor_price' => 80,
        'dealer_price' => 90,
        'retail_price' => 95,
        'status' => true,
        'manufacturing_enabled' => true,
    ]);

    $bom = Bom::query()->create([
        'product_id' => $product->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
        'wastage_percentage' => 0,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $material->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($material))->toBeFalse();
    expect(fn () => $material->delete())->toThrow(SafeDeleteBlockedException::class);
});

it('allows deleting an unused raw material', function () {
    $material = RawMaterial::query()->create([
        'material_name' => 'Unused RM',
        'unit' => 'Kg',
        'status' => true,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($material))->toBeTrue();
    $material->delete();
    expect(RawMaterial::query()->find($material->id))->toBeNull();
});

it('partially applies bulk delete protection for mixed product selection', function () {
    $unusedA = Product::query()->create([
        'product_name' => 'Bulk Unused A',
        'gst_percentage' => 5,
        'mrp' => 10,
        'distributor_price' => 8,
        'dealer_price' => 9,
        'retail_price' => 9.5,
        'status' => true,
    ]);
    $unusedB = Product::query()->create([
        'product_name' => 'Bulk Unused B',
        'gst_percentage' => 5,
        'mrp' => 10,
        'distributor_price' => 8,
        'dealer_price' => 9,
        'retail_price' => 9.5,
        'status' => true,
    ]);
    $used = Product::query()->create([
        'product_name' => 'Bulk Used',
        'gst_percentage' => 5,
        'mrp' => 10,
        'distributor_price' => 8,
        'dealer_price' => 9,
        'retail_price' => 9.5,
        'status' => true,
    ]);

    Bom::query()->create([
        'product_id' => $used->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Inactive,
        'wastage_percentage' => 0,
    ]);

    $deleted = 0;
    $blocked = 0;
    $guard = app(SafeDeleteGuard::class);

    foreach ([$unusedA, $unusedB, $used] as $product) {
        if ($guard->canDelete($product)) {
            $product->delete();
            $deleted++;
        } else {
            $blocked++;
        }
    }

    expect($deleted)->toBe(2)
        ->and($blocked)->toBe(1)
        ->and(Product::query()->find($used->id))->not->toBeNull();
});

it('blocks deleting packaging material used in BOM and allows unused packaging', function () {
    $unused = PackagingMaterial::query()->create([
        'packaging_name' => 'Unused Pack',
        'unit' => 'Nos',
        'status' => true,
    ]);
    $used = PackagingMaterial::query()->create([
        'packaging_name' => 'Used Pack',
        'unit' => 'Nos',
        'status' => true,
    ]);

    $product = Product::query()->create([
        'product_name' => 'Pack BOM Product',
        'gst_percentage' => 18,
        'mrp' => 100,
        'distributor_price' => 80,
        'dealer_price' => 90,
        'retail_price' => 95,
        'status' => true,
        'manufacturing_enabled' => true,
    ]);

    $bom = Bom::query()->create([
        'product_id' => $product->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
        'wastage_percentage' => 0,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $used->id,
        'required_quantity' => 1,
        'unit' => 'Nos',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($unused))->toBeTrue()
        ->and(app(SafeDeleteGuard::class)->canDelete($used))->toBeFalse();

    $unused->delete();
    expect(fn () => $used->delete())->toThrow(SafeDeleteBlockedException::class);
});

it('blocks deleting semi-finished material used as BOM output', function () {
    $material = SemiFinishedMaterial::query()->create([
        'material_name' => 'Linked SFM',
        'unit' => 'Kg',
        'status' => true,
    ]);

    Bom::query()->create([
        'output_type' => BomOutputType::SemiFinished,
        'semi_finished_id' => $material->id,
        'product_id' => null,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Kg',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
        'wastage_percentage' => 0,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($material))->toBeFalse();
    expect(fn () => $material->delete())->toThrow(SafeDeleteBlockedException::class);
});

it('blocks deleting a supplier used in raw material inward', function () {
    $supplier = Supplier::query()->create([
        'supplier_name' => 'Used Supplier',
        'status' => true,
    ]);

    RawMaterialInward::query()->create([
        'inward_number' => 'RMI-SAFE-001',
        'supplier_id' => $supplier->id,
        'inward_date' => now()->toDateString(),
        'status' => RawMaterialInwardStatus::Posted,
    ]);

    expect(app(SafeDeleteGuard::class)->canDelete($supplier))->toBeFalse();
    expect(fn () => $supplier->delete())->toThrow(SafeDeleteBlockedException::class);
});
