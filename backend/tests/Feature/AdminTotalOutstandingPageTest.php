<?php

use App\Enums\UserRole;
use App\Filament\Pages\TotalOutstanding;
use App\Filament\Widgets\AdminDirectorCollectionOutstandingWidget;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\User;
use App\Services\Dealers\DealerLedgerService;
use App\Support\IndianCurrency;
use Livewire\Livewire;

function outstandingPageDirector(): User
{
    return User::query()->create([
        'name' => 'Outstanding Director',
        'email' => 'outstanding.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function outstandingPageEmployee(string $name, string $mobile): Employee
{
    static $n = 800;
    $n++;

    $employee = Employee::query()->create([
        'full_name' => $name,
        'mobile' => $mobile,
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (800000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'OOOOO'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (800000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);

    User::query()->create([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'employee_id' => $employee->id,
    ]);

    return $employee;
}

function outstandingPageDealer(array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'Outstanding Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '97'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'credit_limit' => 0,
        'outstanding' => 0,
    ], $overrides));
}

it('links the admin total outstanding card to the employee-wise outstanding page', function (): void {
    $director = outstandingPageDirector();

    $this->actingAs($director);

    expect(TotalOutstanding::canAccess())->toBeTrue();

    Livewire::actingAs($director)
        ->test(AdminDirectorCollectionOutstandingWidget::class)
        ->assertSuccessful()
        ->assertSee(TotalOutstanding::getUrl(), false);
});

it('shows employee-wise outstanding by default and dealer-wise outstanding for a selected employee', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000001');
    $ganesh = outstandingPageEmployee('Ganesh Outstanding', '9940000002');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 200000,
        'opening_balance_date' => '2026-04-01',
    ]);
    $mid = outstandingPageDealer([
        'firm_name' => 'Mid Balance Dealer',
        'village' => 'Kharadi',
        'assigned_employee_id' => $ganesh->id,
        'opening_balance' => 80000,
        'opening_balance_date' => '2026-04-01',
    ]);
    $zero = outstandingPageDealer([
        'firm_name' => 'Zero Balance Dealer',
        'village' => 'Hadapsar',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 0,
        'opening_balance_date' => '2026-04-01',
    ]);
    $low = outstandingPageDealer([
        'firm_name' => 'Low Balance Dealer',
        'village' => 'Mundhwa',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 25000,
        'opening_balance_date' => '2026-04-01',
    ]);

    $companyTotal = app(DealerLedgerService::class)->companyTotalOutstanding();

    $page = Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->assertSuccessful()
        ->assertSee('All Employees')
        ->assertSee('Outstanding by Employee')
        ->assertSee('Akash Outstanding')
        ->assertSee('Ganesh Outstanding')
        ->assertSee(IndianCurrency::format($companyTotal))
        ->assertSee(IndianCurrency::format(225000))
        ->assertSee(IndianCurrency::format(80000))
        ->assertCanSeeTableRecords([$high, $mid, $low])
        ->assertCanNotSeeTableRecords([$zero]);

    $page->call('selectEmployee', $akash->id)
        ->assertSee(IndianCurrency::format(225000))
        ->assertDontSee('Outstanding by Employee')
        ->assertCanSeeTableRecords([$high, $low])
        ->assertCanNotSeeTableRecords([$mid, $zero]);
});
