<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Resources\Dealers\Pages\CreateDealer;
use App\Filament\Resources\Dealers\Pages\EditDealer;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\User;
use Livewire\Livewire;

function dealerFormAdmin(): User
{
    return User::query()->create([
        'name' => 'Dealer Form Admin',
        'email' => 'dealer.form.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function dealerFormEmployee(): Employee
{
    static $n = 0;
    $n++;

    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Dealer Form Employee '.$n,
        'mobile' => (string) (9600000000 + $n),
        'email' => "dealer.form.emp.{$n}@example.com",
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad((string) (410000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (310000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

it('creates a dealer with optional gst pan and pincode left blank', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();

    Livewire::actingAs($admin)
        ->test(CreateDealer::class)
        ->fillForm([
            'firm_name' => 'Blank Optional Dealer',
            'owner_name' => 'Owner',
            'dealer_type' => 'Retailer',
            'status' => true,
            'assigned_employee_id' => $employee->id,
            'mobile' => '9876543210',
            'email' => null,
            'gst_no' => null,
            'pan_no' => null,
            'fertilizer_license_no' => null,
            'address' => '123 Test Street',
            'state' => 'Maharashtra',
            'district' => 'Pune',
            'taluka' => 'Haveli',
            'village' => 'Wagholi',
            'pincode' => null,
            'credit_limit' => 0,
            'opening_balance' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $dealer = Dealer::query()->where('firm_name', 'Blank Optional Dealer')->first();

    expect($dealer)->not->toBeNull()
        ->and($dealer->mobile)->toBe('9876543210')
        ->and($dealer->gst_no)->toBeNull()
        ->and($dealer->pan_no)->toBeNull()
        ->and($dealer->pincode)->toBeNull()
        ->and($dealer->opening_balance_type)->toBe('debit');
});

it('creates a dealer with valid mobile gst and pan values', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();

    Livewire::actingAs($admin)
        ->test(CreateDealer::class)
        ->fillForm([
            'firm_name' => 'GST PAN Dealer',
            'owner_name' => 'Owner',
            'dealer_type' => 'Retailer',
            'status' => true,
            'assigned_employee_id' => $employee->id,
            'mobile' => '9123456789',
            'gst_no' => '27AAAAA0000A1Z5',
            'pan_no' => 'AAAAA0000A',
            'address' => '123 Test Street',
            'state' => 'Maharashtra',
            'district' => 'Pune',
            'taluka' => 'Haveli',
            'village' => 'Wagholi',
            'pincode' => '411001',
            'credit_limit' => 0,
            'opening_balance' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $dealer = Dealer::query()->where('firm_name', 'GST PAN Dealer')->first();

    expect($dealer)->not->toBeNull()
        ->and($dealer->gst_no)->toBe('27AAAAA0000A1Z5')
        ->and($dealer->pan_no)->toBe('AAAAA0000A')
        ->and($dealer->pincode)->toBe('411001');
});

it('edits an existing dealer without nullable regex validation errors', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();
    $dealer = Dealer::query()->create([
        'firm_name' => 'Existing Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9988776655',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    Livewire::actingAs($admin)
        ->test(EditDealer::class, ['record' => $dealer->getKey()])
        ->fillForm([
            'firm_name' => 'Existing Dealer Updated',
            'gst_no' => null,
            'pan_no' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($dealer->refresh()->firm_name)->toBe('Existing Dealer Updated')
        ->and($dealer->mobile)->toBe('9988776655')
        ->and($dealer->pincode)->toBe('411001');
});

it('defaults dealer create state to Maharashtra and saves Jalna taluka selections', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();

    Livewire::actingAs($admin)
        ->test(CreateDealer::class)
        ->assertFormSet(['state' => 'Maharashtra'])
        ->fillForm([
            'firm_name' => 'Jalna Village Dealer',
            'owner_name' => 'Owner',
            'dealer_type' => 'Retailer',
            'status' => true,
            'assigned_employee_id' => $employee->id,
            'mobile' => '9876501234',
            'address' => 'Shop 1',
            'state' => 'Maharashtra',
            'district' => 'Jalna',
            'taluka' => 'Ghansawangi',
            'village' => 'Tirthpuri',
            'credit_limit' => 0,
            'opening_balance' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $dealer = Dealer::query()->where('firm_name', 'Jalna Village Dealer')->first();

    expect($dealer)->not->toBeNull()
        ->and($dealer->state)->toBe('Maharashtra')
        ->and($dealer->district)->toBe('Jalna')
        ->and($dealer->taluka)->toBe('Ghansawangi')
        ->and($dealer->village)->toBe('Tirthpuri');
});

it('rejects a taluka that does not belong to the selected district', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();

    Livewire::actingAs($admin)
        ->test(CreateDealer::class)
        ->fillForm([
            'firm_name' => 'Mismatched Location Dealer',
            'assigned_employee_id' => $employee->id,
            'mobile' => '9876501235',
            'state' => 'Maharashtra',
            'district' => 'Jalna',
            'taluka' => 'Haveli',
            'village' => 'Tirthpuri',
        ])
        ->call('create')
        ->assertHasFormErrors(['taluka']);

    expect(Dealer::query()->where('firm_name', 'Mismatched Location Dealer')->exists())->toBeFalse();
});

it('loads old district names onto official values when editing a dealer', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();
    $dealer = Dealer::query()->create([
        'firm_name' => 'Aurangabad Legacy Dealer',
        'owner_name' => 'Owner',
        'mobile' => '9988776611',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Aurangabad',
        'taluka' => 'Paithan',
        'village' => 'Paithan Gaon',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);

    Livewire::actingAs($admin)
        ->test(EditDealer::class, ['record' => $dealer->getKey()])
        ->assertFormSet([
            'state' => 'Maharashtra',
            'district' => 'Chhatrapati Sambhajinagar',
            'taluka' => 'Paithan',
            'village' => 'Paithan Gaon',
        ]);

    expect($dealer->refresh()->district)->toBe('Aurangabad');
});

it('saves opening balance type from dealer create and edit', function (): void {
    $admin = dealerFormAdmin();
    $employee = dealerFormEmployee();

    Livewire::actingAs($admin)
        ->test(CreateDealer::class)
        ->assertFormSet(['opening_balance_type' => 'debit'])
        ->fillForm([
            'firm_name' => 'Credit Opening Dealer',
            'owner_name' => 'Owner',
            'dealer_type' => 'Retailer',
            'status' => true,
            'assigned_employee_id' => $employee->id,
            'mobile' => '9876501999',
            'address' => '123 Test Street',
            'state' => 'Maharashtra',
            'district' => 'Pune',
            'taluka' => 'Haveli',
            'village' => 'Wagholi',
            'credit_limit' => 0,
            'opening_balance' => 25000,
            'opening_balance_type' => 'credit',
            'opening_balance_date' => '2026-04-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $dealer = Dealer::query()->where('firm_name', 'Credit Opening Dealer')->first();

    expect($dealer)->not->toBeNull()
        ->and((float) $dealer->opening_balance)->toBe(25000.0)
        ->and($dealer->opening_balance_type)->toBe('credit');
});
