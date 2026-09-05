<?php

use App\Enums\UserRole;
use App\Filament\Resources\Dealers\Pages\EditDealer;
use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Dealer;
use App\Models\Product;
use App\Models\User;
use App\Actions\Employees\CreateEmployeeWithUserAccount;
use Livewire\Livewire;

function editBehaviorDirector(): User
{
    return User::query()->create([
        'name' => 'Edit Behavior Director',
        'email' => 'edit.behavior.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function editBehaviorProduct(array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'product_name' => 'GST Edit Product '.uniqid(),
        'uom' => 'Kg',
        'nos_per_case' => 1,
        'dealer_price' => 100,
        'gst_percentage' => 18,
        'status' => true,
    ], $overrides));
}

it('loads the saved gst percentage on product edit instead of the placeholder', function () {
    $admin = editBehaviorDirector();
    $product = editBehaviorProduct(['gst_percentage' => 18]);

    expect((string) $product->gst_percentage)->toBe('18.00');

    Livewire::actingAs($admin)
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet([
            'gst_percentage' => '18',
            'product_name' => $product->product_name,
            'uom' => 'Kg',
            'status' => true,
        ]);
});

it('returns to the previous product list url with query state after save', function () {
    $admin = editBehaviorDirector();
    $product = editBehaviorProduct();
    $previous = ProductResource::getUrl('index').'?tableSearch=GoldMix&page=2';

    Livewire::actingAs($admin)
        ->withQueryParams(['returnUrl' => $previous])
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'product_name' => $product->product_name.' Updated',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect($previous);

    expect($product->fresh()->product_name)->toBe($product->product_name.' Updated');
});

it('rejects an external return url and falls back to the resource index', function () {
    $admin = editBehaviorDirector();
    $product = editBehaviorProduct();

    Livewire::actingAs($admin)
        ->withQueryParams(['returnUrl' => 'https://evil.example/phish'])
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(ProductResource::getUrl('index'));
});

it('returns to the previous dealer list after saving dealer changes', function () {
    $admin = editBehaviorDirector();
    $employee = app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Return Dealer Employee',
        'mobile' => '9611100099',
        'email' => 'return.dealer.emp.'.uniqid().'@example.com',
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => '412345678901',
        'pan_number' => 'ABCDE1234F',
        'bank_name' => 'Test Bank',
        'account_number' => '310000009901',
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;

    $dealer = Dealer::query()->create([
        'firm_name' => 'Return State Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9988776611',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    $previous = DealerResource::getUrl('index').'?tableSearch=ReturnState';

    Livewire::actingAs($admin)
        ->withQueryParams(['returnUrl' => $previous])
        ->test(EditDealer::class, ['record' => $dealer->getRouteKey()])
        ->assertFormSet([
            'district' => 'Pune',
            'taluka' => 'Haveli',
            'state' => 'Maharashtra',
        ])
        ->fillForm(['firm_name' => 'Return State Dealer Updated'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect($previous);
});
