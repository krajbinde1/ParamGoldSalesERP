<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\Dealer;
use App\Models\DealerApplication;
use App\Models\DealerApplicationDocument;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function dealerAppEmployee(array $overrides = []): \App\Models\Employee
{
    static $counter = 9300000000;
    $counter++;

    return app(CreateEmployeeWithUserAccount::class)->execute(array_merge([
        'full_name' => 'Dealer App Employee '.$counter,
        'mobile' => (string) $counter,
        'email' => "dealer.app.{$counter}@example.com",
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
        'role' => UserRole::Employee->value,
    ], $overrides))->employee->refresh();
}

function dealerAppManager(): \App\Models\Employee
{
    return dealerAppEmployee([
        'full_name' => 'Dealer App Manager',
        'designation' => 'Sales Manager',
        'role' => UserRole::Manager->value,
    ]);
}

function dealerAppAdmin(): User
{
    return User::query()->create([
        'name' => 'Admin Approver',
        'email' => 'dealer.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function dealerAppPayload(array $overrides = []): array
{
    static $mobile = 9600000000;
    $mobile++;

    return array_merge([
        'firm_name' => 'Green Field Agro '.$mobile,
        'owner_name' => 'Ramesh Patil',
        'mobile' => (string) $mobile,
        'gst_no' => '27AABCU9603R1ZM',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'address' => 'Shop 12, Market Road',
        'latitude' => 18.5204,
        'longitude' => 73.8567,
    ], $overrides);
}

function attachAllDealerDocuments(DealerApplication $application, User $uploader): void
{
    foreach (array_keys(DealerApplicationDocument::TYPE_LABELS) as $type) {
        DealerApplicationDocument::query()->create([
            'dealer_application_id' => $application->id,
            'document_type' => $type,
            'file_path' => 'dealer-applications/'.$application->id.'/'.$type.'.pdf',
            'original_filename' => $type.'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);
    }
}

it('does not create a dealer or dealer code when an employee saves or submits an application', function () {
    $employee = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $create = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->assertCreated();
    $id = $create->json('data.id');

    expect(Dealer::count())->toBe(0)
        ->and($create->json('data.dealer_code'))->toBeNull()
        ->and($create->json('data.status'))->toBe(DealerApplication::STATUS_DRAFT);

    attachAllDealerDocuments(DealerApplication::query()->findOrFail($id), $employee->user);

    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')
        ->assertOk()
        ->assertJsonPath('data.status', DealerApplication::STATUS_PENDING_MANAGER)
        ->assertJsonPath('data.dealer_code', null);

    expect(Dealer::count())->toBe(0)
        ->and(Party::count())->toBe(0);
});

it('lets a manager approve without generating a dealer code and hides other teams', function () {
    $manager = dealerAppManager();
    $employee = dealerAppEmployee(['reporting_manager_id' => $manager->id]);
    $otherManager = dealerAppManager();
    $otherEmployee = dealerAppEmployee(['reporting_manager_id' => $otherManager->id]);

    $this->actingAs($employee->user, 'sanctum');
    $ownId = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($ownId), $employee->user);
    $this->postJson('/api/employee/dealer-applications/'.$ownId.'/submit')->assertOk();

    $this->actingAs($otherEmployee->user, 'sanctum');
    $otherId = $this->postJson('/api/employee/dealer-applications', dealerAppPayload([
        'firm_name' => 'Other Team Agro',
        'mobile' => '9876500001',
        'gst_no' => null,
    ]))->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($otherId), $otherEmployee->user);
    $this->postJson('/api/employee/dealer-applications/'.$otherId.'/submit')->assertOk();

    $this->actingAs($manager->user, 'sanctum');
    $this->getJson('/api/manager/dealer-applications?tab=pending')
        ->assertOk()
        ->assertJsonPath('counts.pending', 1)
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/manager/dealer-applications/'.$otherId)->assertForbidden();

    $this->postJson('/api/manager/dealer-applications/'.$ownId.'/approve', ['remark' => 'Looks good'])
        ->assertOk()
        ->assertJsonPath('data.status', DealerApplication::STATUS_PENDING_ADMIN)
        ->assertJsonPath('data.dealer_code', null);

    expect(Dealer::count())->toBe(0)
        ->and(Party::count())->toBe(0);
});

it('generates a unique dealer code and party only after admin final approval and is idempotent', function () {
    $manager = dealerAppManager();
    $employee = dealerAppEmployee(['reporting_manager_id' => $manager->id]);
    $admin = dealerAppAdmin();

    $this->actingAs($employee->user, 'sanctum');
    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($id), $employee->user);
    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')->assertOk();

    $this->actingAs($manager->user, 'sanctum');
    $this->postJson('/api/manager/dealer-applications/'.$id.'/approve')->assertOk();

    $application = DealerApplication::query()->findOrFail($id);
    $finalized = app(\App\Actions\DealerApplications\FinalizeDealerApplication::class)
        ->execute($application, $admin, 'Final OK');

    expect($finalized->status)->toBe(DealerApplication::STATUS_APPROVED)
        ->and($finalized->dealer_id)->not->toBeNull()
        ->and($finalized->party_id)->not->toBeNull()
        ->and($finalized->dealer->dealer_code)->toStartWith('D')
        ->and($finalized->dealer->assigned_employee_id)->toBe($employee->id)
        ->and(Dealer::count())->toBe(1)
        ->and(Party::count())->toBe(1);

    $again = app(\App\Actions\DealerApplications\FinalizeDealerApplication::class)
        ->execute($finalized->fresh(), $admin);

    expect(Dealer::count())->toBe(1)
        ->and(Party::count())->toBe(1)
        ->and($again->dealer_id)->toBe($finalized->dealer_id)
        ->and($again->party_id)->toBe($finalized->party_id);
});

it('resubmits the same application after send back and does not create a duplicate', function () {
    $manager = dealerAppManager();
    $employee = dealerAppEmployee(['reporting_manager_id' => $manager->id]);

    $this->actingAs($employee->user, 'sanctum');
    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($id), $employee->user);
    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')->assertOk();

    $this->actingAs($manager->user, 'sanctum');
    $this->postJson('/api/manager/dealer-applications/'.$id.'/send-back', [
        'remark' => 'Please correct GST details',
    ])->assertOk()->assertJsonPath('data.status', DealerApplication::STATUS_CORRECTION_REQUIRED);

    $this->actingAs($employee->user, 'sanctum');
    $this->putJson('/api/employee/dealer-applications/'.$id, dealerAppPayload([
        'firm_name' => 'Green Field Agro Corrected',
        'mobile' => '9876511111',
        'gst_no' => null,
    ]))->assertOk();

    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', DealerApplication::STATUS_PENDING_MANAGER);

    expect(DealerApplication::count())->toBe(1);
});

it('stores each document type separately and denies outsiders', function () {
    Storage::fake('local');

    $employee = dealerAppEmployee();
    $outsider = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');

    $upload = $this->post('/api/employee/dealer-applications/'.$id.'/documents', [
        'document_type' => DealerApplicationDocument::TYPE_OWNER_AADHAAR,
        'file' => UploadedFile::fake()->image('aadhaar.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $documentId = $upload->json('data.id');
    expect($upload->json('data.document_type'))->toBe(DealerApplicationDocument::TYPE_OWNER_AADHAAR)
        ->and($upload->json('data.uploaded'))->toBeTrue();

    $this->getJson('/api/dealer-applications/'.$id.'/documents/'.$documentId)->assertOk();

    $this->actingAs($outsider->user, 'sanctum');
    $this->getJson('/api/dealer-applications/'.$id.'/documents/'.$documentId)->assertForbidden();
    $this->getJson('/api/employee/dealer-applications/'.$id)->assertForbidden();
});

it('warns about possible duplicates without blocking submit', function () {
    $employee = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $payload = dealerAppPayload(['mobile' => '9876522222']);
    $firstId = $this->postJson('/api/employee/dealer-applications', $payload)->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($firstId), $employee->user);
    $this->postJson('/api/employee/dealer-applications/'.$firstId.'/submit')->assertOk();

    $second = $this->postJson('/api/employee/dealer-applications', $payload)->assertCreated();
    expect($second->json('duplicate_warning'))->toBeTrue();

    $secondId = $second->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($secondId), $employee->user);

    $this->postJson('/api/employee/dealer-applications/'.$secondId.'/submit')
        ->assertOk()
        ->assertJsonPath('duplicate_warning', true)
        ->assertJsonPath('data.status', DealerApplication::STATUS_PENDING_MANAGER);
});

it('requires shop gps location before submit', function () {
    $employee = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload([
        'latitude' => null,
        'longitude' => null,
    ]))->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($id), $employee->user);

    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['location']);
});

it('lets an employee remove an uploaded supporting document from a draft', function () {
    Storage::fake('local');

    $employee = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');
    $upload = $this->post('/api/employee/dealer-applications/'.$id.'/documents', [
        'document_type' => DealerApplicationDocument::TYPE_OWNER_AADHAAR,
        'file' => UploadedFile::fake()->image('aadhaar.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $documentId = $upload->json('data.id');
    $this->deleteJson('/api/employee/dealer-applications/'.$id.'/documents/'.$documentId)
        ->assertOk()
        ->assertJsonPath('data.documents.5.uploaded', false);

    expect(DealerApplicationDocument::query()->whereKey($documentId)->exists())->toBeFalse();
});

it('requires a remark when manager rejects or sends back', function () {
    $manager = dealerAppManager();
    $employee = dealerAppEmployee(['reporting_manager_id' => $manager->id]);

    $this->actingAs($employee->user, 'sanctum');
    $id = $this->postJson('/api/employee/dealer-applications', dealerAppPayload())->json('data.id');
    attachAllDealerDocuments(DealerApplication::query()->findOrFail($id), $employee->user);
    $this->postJson('/api/employee/dealer-applications/'.$id.'/submit')->assertOk();

    $this->actingAs($manager->user, 'sanctum');
    $this->postJson('/api/manager/dealer-applications/'.$id.'/reject')->assertUnprocessable();
    $this->postJson('/api/manager/dealer-applications/'.$id.'/send-back')->assertUnprocessable();
});

it('rejects a dealer application taluka that does not belong to the selected district', function (): void {
    $employee = dealerAppEmployee();
    $this->actingAs($employee->user, 'sanctum');

    $this->postJson('/api/employee/dealer-applications', dealerAppPayload([
        'district' => 'Jalna',
        'taluka' => 'Haveli',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['taluka']);
});
