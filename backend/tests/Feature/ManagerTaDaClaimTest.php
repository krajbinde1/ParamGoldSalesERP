<?php

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\TaDaClaim;
use App\Models\TaDaSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function managerTaDaPerson(string $role, string $mobile, string $name): \App\Models\Employee
{
    return taDaEmployee([
        'full_name' => $name,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
        'aadhaar_number' => '91'.substr($mobile, -10),
        'pan_number' => 'ABCDE'.substr($mobile, -4).'F',
        'account_number' => '88'.$mobile,
        'role' => $role,
    ]);
}

function managerTaDaAttendance(\App\Models\Employee $employee, string $date): void
{
    $attendance = Attendance::query()->create([
        'employee_id' => $employee->id,
        'attendance_date' => $date,
        'punch_in_time' => '09:00',
        'punch_out_time' => '18:00',
        'attendance_status' => 'Present',
        'approval_status' => 'Pending',
    ]);
    seedRouteForAttendance($attendance);
}

it('sends a manager TA bill to the director and keeps employee approval with the manager', function () {
    Storage::fake('public');
    TaDaSetting::query()->create([
        'per_km_rate' => 5.00,
        'is_active' => true,
    ]);

    $manager = managerTaDaPerson(UserRole::Manager->value, '9811100101', 'Manager One');
    $otherManager = managerTaDaPerson(UserRole::Manager->value, '9811100102', 'Manager Two');
    $director = managerTaDaPerson(UserRole::Director->value, '9811100103', 'Director One');
    $sales = managerTaDaPerson(UserRole::Employee->value, '9811100104', 'Sales One');
    $sales->forceFill(['reporting_manager_id' => $manager->id])->save();

    managerTaDaAttendance($manager, '2026-08-01');
    managerTaDaAttendance($manager, '2026-08-02');
    managerTaDaAttendance($sales, '2026-08-03');

    $photo = UploadedFile::fake()->image('bill.jpg');

    $created = $this->actingAs($manager->user, 'sanctum')
        ->post('/api/manager/my-ta-da-claims', [
            'claim_date' => '2026-08-01',
            'from_location' => 'Office',
            'to_location' => 'Field',
            'da_amount' => 100,
            'other_expense' => 50,
            'employee_remarks' => 'Manager travel',
            'photo' => $photo,
        ]);

    $created->assertCreated()
        ->assertJsonPath('data.status', TaDaClaim::STATUS_PENDING)
        ->assertJsonPath('data.status_label', 'Pending Director Approval')
        ->assertJsonPath('data.submitter_role', 'manager');

    $claim = TaDaClaim::query()->findOrFail($created->json('data.id'));
    expect($claim->submitter_role)->toBe('manager')
        ->and((float) $claim->total_amount)->toBe(round((float) $claim->travel_amount + 150, 2))
        ->and(AppNotification::query()->where('user_id', $director->user->id)->where('type', 'ta_da_manager_submitted')->count())->toBe(1);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/ta-da-claims/{$claim->id}/approve")
        ->assertForbidden();

    $this->actingAs($otherManager->user, 'sanctum')
        ->getJson("/api/manager/my-ta-da-claims/{$claim->id}")
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/ta-da-claims?status=pending')
        ->assertOk()
        ->assertJsonMissing(['id' => $claim->id]);

    $this->actingAs($director->user, 'sanctum')
        ->getJson('/api/director/ta-da-claims?status=pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $claim->id)
        ->assertJsonPath('counts.pending', 1);

    $this->actingAs($director->user, 'sanctum')
        ->getJson("/api/director/ta-da-claims/{$claim->id}")
        ->assertOk()
        ->assertJsonPath('data.employee_name', 'Manager One');

    $this->actingAs($director->user, 'sanctum')
        ->postJson("/api/director/ta-da-claims/{$claim->id}/reject", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['remark']);

    $this->actingAs($director->user, 'sanctum')
        ->postJson("/api/director/ta-da-claims/{$claim->id}/approve")
        ->assertOk();

    $claim->refresh();
    expect($claim->status)->toBe(TaDaClaim::STATUS_APPROVED)
        ->and($claim->approver_role)->toBe('director')
        ->and($claim->approved_by)->toBe($director->user->id)
        ->and(AppNotification::query()->where('user_id', $manager->user->id)->where('type', 'ta_da_approved')->count())->toBe(1);

    $second = $this->actingAs($manager->user, 'sanctum')
        ->post('/api/manager/my-ta-da-claims', [
            'claim_date' => '2026-08-02',
            'from_location' => 'Office',
            'to_location' => 'Market',
            'da_amount' => 0,
            'other_expense' => 0,
            'photo' => UploadedFile::fake()->image('bill-2.jpg'),
        ])
        ->assertCreated();

    $secondId = $second->json('data.id');
    $this->actingAs($director->user, 'sanctum')
        ->postJson("/api/director/ta-da-claims/{$secondId}/reject", [
            'remark' => 'Bills are incomplete.',
        ])
        ->assertOk();

    $rejected = TaDaClaim::query()->findOrFail($secondId);
    expect($rejected->status)->toBe(TaDaClaim::STATUS_REJECTED)
        ->and($rejected->admin_remark)->toBe('Bills are incomplete.')
        ->and($rejected->approver_role)->toBe('director');

    $this->actingAs($manager->user, 'sanctum')
        ->getJson("/api/manager/my-ta-da-claims/{$secondId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.admin_remark', 'Bills are incomplete.');

    $employeeClaim = $this->actingAs($sales->user, 'sanctum')
        ->post('/api/employee/ta-da-claims', [
            'claim_date' => '2026-08-03',
            'from_location' => 'Office',
            'to_location' => 'Dealer',
            'da_amount' => 20,
            'other_expense' => 0,
            'photo' => UploadedFile::fake()->image('sales.jpg'),
        ])
        ->assertCreated();

    $employeeClaimId = $employeeClaim->json('data.id');
    $employeeRow = TaDaClaim::query()->findOrFail($employeeClaimId);
    expect($employeeRow->submitter_role)->toBe('employee');

    $this->actingAs($director->user, 'sanctum')
        ->getJson('/api/director/ta-da-claims?status=pending')
        ->assertOk()
        ->assertJsonMissing(['id' => $employeeClaimId]);

    $this->actingAs($director->user, 'sanctum')
        ->postJson("/api/director/ta-da-claims/{$employeeClaimId}/approve")
        ->assertForbidden();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/ta-da-claims/{$employeeClaimId}/approve")
        ->assertOk();

    expect(TaDaClaim::query()->find($employeeClaimId)->status)->toBe(TaDaClaim::STATUS_APPROVED)
        ->and(TaDaClaim::query()->find($employeeClaimId)->approver_role)->toBe('manager');
});
