<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\DealerVisit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    Carbon::setTestNow(Carbon::parse('2026-08-22 16:20:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function prospectiveVisitEmployee(string $mobile = '9811100099'): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Visit Employee',
        'mobile' => $mobile,
        'email' => 'visit.'.$mobile.'@example.com',
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance_type' => 'actual_expense',
        'company_card_issued' => false,
        'monthly_travel_expense_limit' => 500,
        'aadhaar_number' => str_pad(substr($mobile, -12), 12, '4', STR_PAD_LEFT),
        'pan_number' => 'ABCDE'.substr($mobile, -4).'F',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad($mobile, 12, '3', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
        'role' => UserRole::Employee->value,
    ])->employee;
}

function prospectiveVisitDealer(\App\Models\Employee $employee): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'Assigned Agro',
        'owner_name' => 'Owner',
        'mobile' => '9822001100',
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
}

it('saves a prospective dealer visit without creating a dealer master record', function (): void {
    $employee = prospectiveVisitEmployee();
    $dealerCount = Dealer::query()->count();

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/employee/dealer-visits', [
            'is_prospective' => 1,
            'firm_name' => 'New Prospective Traders',
            'owner_name' => 'Ramesh Patil',
            'mobile' => '9876543210',
            'village' => 'Tirthpuri',
            'taluka' => 'Partur',
            'district' => 'Jalna',
            'remarks' => 'Interested in cotton pack',
            'latitude' => 19.8765432,
            'longitude' => 75.3432109,
            'accuracy' => 8.5,
            'location_captured_at' => Carbon::now('Asia/Kolkata')->toIso8601String(),
            'photo' => UploadedFile::fake()->image('visit.jpg'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_prospective', true)
        ->assertJsonPath('data.dealer_id', null)
        ->assertJsonPath('data.dealer_name', 'New Prospective Traders')
        ->assertJsonPath('data.owner_name', 'Ramesh Patil')
        ->assertJsonPath('data.mobile', '9876543210')
        ->assertJsonPath('data.village', 'Tirthpuri')
        ->assertJsonPath('data.taluka', 'Partur')
        ->assertJsonPath('data.district', 'Jalna')
        ->assertJsonPath('data.remarks', 'Interested in cotton pack');

    expect(Dealer::query()->count())->toBe($dealerCount)
        ->and(DealerVisit::query()->where('is_prospective', true)->count())->toBe(1)
        ->and(DealerVisit::query()->whereNull('dealer_id')->count())->toBe(1);
});

it('still submits an existing assigned dealer visit', function (): void {
    $employee = prospectiveVisitEmployee('9811100088');
    $dealer = prospectiveVisitDealer($employee);

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/employee/dealer-visits', [
            'dealer_id' => $dealer->id,
            'latitude' => 19.8765432,
            'longitude' => 75.3432109,
            'accuracy' => 8.5,
            'location_captured_at' => Carbon::now('Asia/Kolkata')->toIso8601String(),
            'photo' => UploadedFile::fake()->image('visit.jpg'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.dealer_id', $dealer->id)
        ->assertJsonPath('data.dealer_name', 'Assigned Agro')
        ->assertJsonPath('data.is_prospective', false);

    expect(DealerVisit::query()->where('dealer_id', $dealer->id)->exists())->toBeTrue();
});
