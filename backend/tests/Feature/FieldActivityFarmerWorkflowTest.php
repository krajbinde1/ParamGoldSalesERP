<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Crop;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\MaharashtraDistrict;
use App\Models\MaharashtraTaluka;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function farmerWorkflowEmployee(array $overrides = []): \App\Models\Employee
{
    static $counter = 9400000000;
    $counter++;

    return app(CreateEmployeeWithUserAccount::class)->execute(array_merge([
        'full_name' => 'Farmer Workflow '.$counter,
        'mobile' => (string) $counter,
        'email' => "farmer.workflow.{$counter}@example.com",
        'department' => 'Sales',
        'designation' => 'Sales Executive',
        'joining_date' => '2026-07-01',
        'salary' => 25000,
        'base_location' => 'Chhatrapati Sambhajinagar',
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
        'role' => UserRole::Employee->value,
    ], $overrides))->employee->refresh();
}

function farmerWorkflowProduct(string $name = 'Param Product A'): Product
{
    return Product::query()->create([
        'product_name' => $name,
        'category' => 'General',
        'uom' => 'Kg',
        'nos_per_case' => 10,
        'gst_percentage' => 18,
        'dealer_price' => 250,
        'status' => true,
    ]);
}

/**
 * @return array{district: MaharashtraDistrict, taluka: MaharashtraTaluka, crop: Crop}
 */
function farmerWorkflowMasters(): array
{
    $district = MaharashtraDistrict::query()->where('name', 'Chhatrapati Sambhajinagar')->firstOrFail();
    $taluka = MaharashtraTaluka::query()
        ->where('district_id', $district->id)
        ->where('name', 'Gangapur')
        ->firstOrFail();
    $crop = Crop::query()->where('name', 'Cotton')->firstOrFail();

    return compact('district', 'taluka', 'crop');
}

/**
 * @param  list<array{product_id: int, dosage?: string, remark?: string}>  $recommendations
 * @return array<string, mixed>
 */
function farmerWorkflowPayload(
    array $masters,
    array $recommendations,
    array $overrides = [],
): array {
    return array_merge([
        'farmer_name' => 'Ramesh Patil',
        'farmer_mobile' => '9876543210',
        'district_id' => $masters['district']->id,
        'taluka_id' => $masters['taluka']->id,
        'village' => 'Waluj',
        'crop_id' => $masters['crop']->id,
        'remark' => 'Use after irrigation',
        'latitude' => 19.8762000,
        'longitude' => 75.3433000,
        'photo' => UploadedFile::fake()->image('visit.jpg'),
        'recommendations' => $recommendations,
    ], $overrides);
}

it('lists maharashtra districts and talukas for the selected district only', function () {
    $employee = farmerWorkflowEmployee();
    $this->actingAs($employee->user);

    $districts = $this->getJson('/api/employee/field-activity-masters/districts')
        ->assertOk()
        ->json('data');

    expect(collect($districts)->pluck('name'))->toContain('Chhatrapati Sambhajinagar', 'Jalna');

    $sambhajinagar = collect($districts)->firstWhere('name', 'Chhatrapati Sambhajinagar');
    $jalna = collect($districts)->firstWhere('name', 'Jalna');

    $talukas = $this->getJson('/api/employee/field-activity-masters/talukas?district_id='.$sambhajinagar['id'])
        ->assertOk()
        ->json('data');

    expect(collect($talukas)->pluck('name'))
        ->toContain('Gangapur')
        ->not->toContain('Partur');

    $jalnaTalukas = $this->getJson('/api/employee/field-activity-masters/talukas?district_id='.$jalna['id'])
        ->assertOk()
        ->json('data');

    expect(collect($jalnaTalukas)->pluck('name'))->toContain('Partur', 'Ambad', 'Bhokardan');
});

it('creates a farmer master with multiple product recommendations on field activity submit', function () {
    $employee = farmerWorkflowEmployee();
    $masters = farmerWorkflowMasters();
    $productA = farmerWorkflowProduct('Param Product A');
    $productB = farmerWorkflowProduct('Param Product B');

    $this->actingAs($employee->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $productA->id, 'dosage' => '1 kg/acre'],
            ['product_id' => $productB->id, 'remark' => 'Use after 15 days'],
        ]), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.farmer_name', 'Ramesh Patil')
        ->assertJsonPath('data.farmer_mobile', '9876543210')
        ->assertJsonPath('data.district', 'Chhatrapati Sambhajinagar')
        ->assertJsonPath('data.taluka', 'Gangapur')
        ->assertJsonPath('data.village', 'Waluj')
        ->assertJsonPath('data.crop_name', 'Cotton')
        ->assertJsonCount(2, 'data.recommendations');

    expect(Farmer::query()->count())->toBe(1)
        ->and(FieldActivity::query()->count())->toBe(1)
        ->and(Farmer::query()->first()->created_by_employee_id)->toBe($employee->id);
});

it('links a second visit to the existing farmer instead of creating a duplicate', function () {
    $employee = farmerWorkflowEmployee();
    $masters = farmerWorkflowMasters();
    $product = farmerWorkflowProduct();

    $this->actingAs($employee->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id, 'dosage' => '1 kg/acre'],
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($employee->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id, 'dosage' => '500 ml/acre'],
        ], [
            'farmer_name' => 'Ramesh B. Patil',
            'village' => 'Waluj MIDC',
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    expect(Farmer::query()->count())->toBe(1)
        ->and(FieldActivity::query()->count())->toBe(2)
        ->and(FieldActivity::query()->pluck('farmer_id')->unique()->count())->toBe(1)
        ->and(Farmer::query()->first()->village)->toBe('Waluj MIDC')
        ->and(Farmer::query()->first()->name)->toBe('Ramesh B. Patil');
});

it('looks up an existing farmer by mobile without exposing other employees photos', function () {
    $employee = farmerWorkflowEmployee();
    $other = farmerWorkflowEmployee();
    $masters = farmerWorkflowMasters();
    $product = farmerWorkflowProduct();

    $this->actingAs($other->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id],
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($employee->user)
        ->getJson('/api/employee/farmers/lookup?mobile=9876543210')
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('data.name', 'Ramesh Patil')
        ->assertJsonPath('data.village', 'Waluj')
        ->assertJsonPath('last_activity.crop_name', 'Cotton')
        ->assertJsonPath('last_activity.own_activity', false)
        ->assertJsonMissingPath('last_activity.photo_url')
        ->assertJsonMissingPath('last_activity.latitude');
});

it('rejects invalid farmer mobile numbers', function () {
    $employee = farmerWorkflowEmployee();
    $masters = farmerWorkflowMasters();
    $product = farmerWorkflowProduct();

    $this->actingAs($employee->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id],
        ], [
            'farmer_mobile' => '1234567890',
        ]), ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('keeps legacy field activities without farmer details working', function () {
    $employee = farmerWorkflowEmployee();

    FieldActivity::query()->create([
        'employee_id' => $employee->id,
        'farmer_name' => 'Legacy Farmer',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-12',
        'activity_time' => '10:00:00',
        'photo_path' => 'field-activities/legacy.jpg',
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($employee->user)
        ->getJson('/api/employee/field-activities')
        ->assertOk()
        ->assertJsonPath('summary.total_activities', 1)
        ->assertJsonPath('recent_activities.0.farmer_name', 'Legacy Farmer')
        ->assertJsonPath('recent_activities.0.farmer_mobile', null);
});

it('lets a manager view only their team farmer field activities', function () {
    User::query()->create([
        'name' => 'Padding Director',
        'email' => 'padding.farmer.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);

    $manager = farmerWorkflowEmployee([
        'full_name' => 'Team Manager',
        'role' => UserRole::Manager->value,
        'designation' => 'Sales Manager',
    ]);
    $otherManager = farmerWorkflowEmployee([
        'full_name' => 'Other Manager',
        'role' => UserRole::Manager->value,
        'designation' => 'Sales Manager',
    ]);
    $report = farmerWorkflowEmployee(['full_name' => 'Direct Report']);
    $foreign = farmerWorkflowEmployee(['full_name' => 'Foreign Sales']);
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreign->update(['reporting_manager_id' => $otherManager->id]);

    $masters = farmerWorkflowMasters();
    $product = farmerWorkflowProduct();

    $this->actingAs($report->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id, 'dosage' => '1 kg/acre'],
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    $this->actingAs($foreign->user)
        ->post('/api/employee/field-activities', farmerWorkflowPayload($masters, [
            ['product_id' => $product->id],
        ], [
            'farmer_mobile' => '9876500001',
            'farmer_name' => 'Hidden Farmer',
        ]), ['Accept' => 'application/json'])
        ->assertCreated();

    $teamActivityId = FieldActivity::query()->where('employee_id', $report->id)->value('id');
    $foreignActivityId = FieldActivity::query()->where('employee_id', $foreign->id)->value('id');

    $this->actingAs($manager->user)
        ->getJson('/api/manager/field-activities')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.farmer_name', 'Ramesh Patil')
        ->assertJsonPath('data.0.employee_id', $report->id);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/field-activities/'.$teamActivityId)
        ->assertOk()
        ->assertJsonPath('data.farmer_mobile', '9876543210')
        ->assertJsonPath('data.crop_name', 'Cotton');

    $this->actingAs($manager->user)
        ->getJson('/api/manager/field-activities/'.$foreignActivityId)
        ->assertForbidden();
});
