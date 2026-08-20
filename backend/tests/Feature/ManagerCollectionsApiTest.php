<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Dealer;
use App\Models\DealerVisit;
use App\Models\FieldActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function managerCollectionsEmployee(UserRole $role, string $mobile, string $name): \App\Models\Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
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

function managerCollectionsDealer(string $firm = 'Team Dealer'): Dealer
{
    return Dealer::query()->create([
        'firm_name' => $firm,
        'owner_name' => 'Owner',
        'mobile' => '9888888'.random_int(100, 999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'outstanding' => 0,
    ]);
}

function managerCollectionsEntry(
    int $employeeId,
    int $dealerId,
    string $date,
    float $amount,
    string $status,
    ?string $remarks = null,
    ?string $photoPath = null,
): Collection {
    return Collection::query()->create([
        'collection_date' => $date,
        'dealer_id' => $dealerId,
        'sales_employee_id' => $employeeId,
        'amount' => $amount,
        'status' => $status,
        'remarks' => $remarks,
        'photo_path' => $photoPath,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-MGR-'.uniqid(),
    ]);
}

it('lets a manager list only collections from direct reports', function () {
    $manager = managerCollectionsEmployee(UserRole::Manager, '9400000001', 'Team Manager');
    $otherManager = managerCollectionsEmployee(UserRole::Manager, '9400000002', 'Other Manager');
    $report = managerCollectionsEmployee(UserRole::Employee, '9400000003', 'Team Sales');
    $foreign = managerCollectionsEmployee(UserRole::Employee, '9400000004', 'Foreign Sales');
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreign->update(['reporting_manager_id' => $otherManager->id]);

    $dealer = managerCollectionsDealer();
    $own = managerCollectionsEntry($report->id, $dealer->id, '2026-08-18', 2500, Collection::STATUS_RECEIVED, 'Cash collected', 'collections/own.jpg');
    $pending = managerCollectionsEntry($report->id, $dealer->id, '2026-08-20', 800, Collection::STATUS_PENDING, 'Pending slip');
    $other = managerCollectionsEntry($foreign->id, $dealer->id, '2026-08-19', 9000, Collection::STATUS_RECEIVED, 'Should not appear');

    $response = $this->actingAs($manager->user)
        ->getJson('/api/manager/collections?period=month')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($own->id, $pending->id)
        ->and($ids)->not->toContain($other->id)
        ->and($response->json('summary.total_collection'))->toBe(2500)
        ->and($response->json('summary.today_collection'))->toBe(0)
        ->and($response->json('summary.month_collection'))->toBe(2500)
        ->and($response->json('summary.pending_entries'))->toBe(1);

    $ownRow = collect($response->json('data'))->firstWhere('id', $own->id);
    expect($ownRow['employee_name'])->toBe('Team Sales')
        ->and($ownRow['dealer_name'])->toBe('Team Dealer')
        ->and($ownRow['remarks'])->toBe('Cash collected')
        ->and($ownRow['photo_url'])->toContain('collections/own.jpg');
});

it('forbids a manager from viewing another team collection and has no status-change route', function () {
    $manager = managerCollectionsEmployee(UserRole::Manager, '9400000011', 'Scoped Manager');
    $otherManager = managerCollectionsEmployee(UserRole::Manager, '9400000012', 'Foreign Manager');
    $report = managerCollectionsEmployee(UserRole::Employee, '9400000013', 'Scoped Sales');
    $foreign = managerCollectionsEmployee(UserRole::Employee, '9400000014', 'Foreign Sales Two');
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreign->update(['reporting_manager_id' => $otherManager->id]);

    $dealer = managerCollectionsDealer('Foreign Dealer');
    $own = managerCollectionsEntry($report->id, $dealer->id, '2026-08-20', 500, Collection::STATUS_PENDING);
    $other = managerCollectionsEntry($foreign->id, $dealer->id, '2026-08-20', 700, Collection::STATUS_PENDING);

    $this->actingAs($manager->user)
        ->getJson("/api/manager/collections/{$own->id}")
        ->assertOk()
        ->assertJsonPath('data.employee_name', 'Scoped Sales');

    $this->actingAs($manager->user)
        ->getJson("/api/manager/collections/{$other->id}")
        ->assertForbidden();

    $this->actingAs($manager->user)
        ->postJson("/api/manager/collections/{$own->id}/received")
        ->assertNotFound();

    $this->actingAs($manager->user)
        ->postJson("/api/manager/collections/{$own->id}/not-received")
        ->assertNotFound();
});

it('filters manager collections by employee and custom date range', function () {
    $manager = managerCollectionsEmployee(UserRole::Manager, '9400000021', 'Filter Manager');
    $first = managerCollectionsEmployee(UserRole::Employee, '9400000022', 'First Report');
    $second = managerCollectionsEmployee(UserRole::Employee, '9400000023', 'Second Report');
    $first->update(['reporting_manager_id' => $manager->id]);
    $second->update(['reporting_manager_id' => $manager->id]);
    $dealer = managerCollectionsDealer();

    $kept = managerCollectionsEntry($first->id, $dealer->id, '2026-08-12', 1200, Collection::STATUS_RECEIVED);
    managerCollectionsEntry($first->id, $dealer->id, '2026-08-01', 300, Collection::STATUS_RECEIVED);
    managerCollectionsEntry($second->id, $dealer->id, '2026-08-12', 400, Collection::STATUS_RECEIVED);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/collections?period=custom&date_from=2026-08-10&date_to=2026-08-15&employee_id='.$first->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id)
        ->assertJsonPath('summary.total_collection', 1500);
});

it('returns captured dealer visit and field activity coordinates for the manager team', function () {
    $manager = managerCollectionsEmployee(UserRole::Manager, '9400000031', 'Activity Manager');
    $otherManager = managerCollectionsEmployee(UserRole::Manager, '9400000032', 'Activity Other Manager');
    $report = managerCollectionsEmployee(UserRole::Employee, '9400000033', 'Activity Sales');
    $foreign = managerCollectionsEmployee(UserRole::Employee, '9400000034', 'Activity Foreign');
    $report->update(['reporting_manager_id' => $manager->id]);
    $foreign->update(['reporting_manager_id' => $otherManager->id]);
    $dealer = managerCollectionsDealer();

    DealerVisit::query()->create([
        'employee_id' => $report->id,
        'dealer_id' => $dealer->id,
        'visit_date' => '2026-08-20',
        'visit_time' => '10:15:00',
        'photo_path' => 'dealer-visits/team.jpg',
        'latitude' => 19.8765432,
        'longitude' => 75.3432109,
        'accuracy' => 8.5,
        'location_captured_at' => '2026-08-20 10:15:00',
        'status' => DealerVisit::STATUS_COMPLETED,
    ]);

    FieldActivity::query()->create([
        'employee_id' => $report->id,
        'farmer_name' => 'Ramesh Patil',
        'village' => 'Waluj',
        'taluka' => 'Gangapur',
        'activity_date' => '2026-08-20',
        'activity_time' => '11:40:00',
        'photo_path' => 'field-activities/team.jpg',
        'latitude' => 19.9012345,
        'longitude' => 75.3123456,
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    FieldActivity::query()->create([
        'employee_id' => $foreign->id,
        'farmer_name' => 'Hidden Farmer',
        'village' => 'Hidden',
        'taluka' => 'Hidden',
        'activity_date' => '2026-08-20',
        'activity_time' => '12:00:00',
        'photo_path' => 'field-activities/hidden.jpg',
        'latitude' => 18.5200000,
        'longitude' => 73.8500000,
        'status' => FieldActivity::STATUS_COMPLETED,
    ]);

    $this->actingAs($manager->user)
        ->getJson('/api/manager/team-activity/employees/'.$foreign->id.'?date=2026-08-20')
        ->assertForbidden();

    $timeline = $this->actingAs($manager->user)
        ->getJson('/api/manager/team-activity/employees/'.$report->id.'?date=2026-08-20')
        ->assertOk();

    $dealerVisit = collect($timeline->json('data'))->firstWhere('type', 'dealer_visit');
    $fieldVisit = collect($timeline->json('data'))->firstWhere('type', 'field_visit');

    expect($dealerVisit['employee_name'])->toBe('Activity Sales')
        ->and($dealerVisit['latitude'])->toBe(19.8765432)
        ->and($dealerVisit['longitude'])->toBe(75.3432109)
        ->and($dealerVisit['maps_url'])->toBe('https://www.google.com/maps?q=19.8765432,75.3432109')
        ->and($dealerVisit['location_available'])->toBeTrue()
        ->and($fieldVisit['type_label'])->toBe('Field Activity')
        ->and($fieldVisit['latitude'])->toBe(19.9012345)
        ->and($fieldVisit['longitude'])->toBe(75.3123456)
        ->and($fieldVisit['maps_url'])->toBe('https://www.google.com/maps?q=19.9012345,75.3123456')
        ->and($fieldVisit['remark'])->toContain('Ramesh Patil');
});
