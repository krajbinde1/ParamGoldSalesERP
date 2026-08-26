<?php

use App\Actions\CreditNotes\CompleteCreditNote;
use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function creditNoteEmployee(UserRole $role, string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => $role->label().' User '.$mobile,
        'mobile' => $mobile,
        'email' => str_replace('_', '.', $role->value).'.'.$mobile.'.cn@example.com',
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

function creditNoteDealer(Employee $employee): Dealer
{
    return Dealer::query()->create([
        'firm_name' => 'Credit Note Dealer '.$employee->id,
        'owner_name' => 'Owner',
        'mobile' => '98'.str_pad((string) $employee->id, 8, '8', STR_PAD_LEFT),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
}

function creditNoteProduct(): Product
{
    return Product::query()->create([
        'product_name' => 'ParamGold 1 Litre',
        'category' => 'General',
        'uom' => 'Litre',
        'nos_per_case' => 20,
        'gst_percentage' => 18,
        'dealer_price' => 150,
        'status' => true,
    ]);
}

function creditNoteAdmin(): User
{
    return User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin.cn.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function salesReturnPayload(Dealer $dealer, Product $product, array $overrides = []): array
{
    return array_merge([
        'type' => CreditNote::TYPE_SALES_RETURN,
        'dealer_id' => $dealer->id,
        'bill_reference' => 'INV-1001',
        'credit_note_date' => now('Asia/Kolkata')->toDateString(),
        'remarks' => 'Customer returned damaged stock',
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'rate' => 150,
                'reason' => 'Damaged packing',
            ],
        ],
    ], $overrides);
}

function rateDifferencePayload(Dealer $dealer, Product $product, array $overrides = []): array
{
    return array_merge([
        'type' => CreditNote::TYPE_RATE_DIFFERENCE,
        'dealer_id' => $dealer->id,
        'bill_reference' => 'INV-2002',
        'credit_note_date' => now('Asia/Kolkata')->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 10,
                'original_rate' => 150,
                'revised_rate' => 140,
                'reason' => 'Rate correction',
            ],
        ],
    ], $overrides);
}

it('lets a sales employee create a sales return credit note with calculated amount', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000001');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated()
        ->assertJsonPath('status', CreditNote::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('amount', 300);

    $note = CreditNote::query()->first();
    expect($note)->not->toBeNull()
        ->and($note->credit_note_no)->toStartWith('CN')
        ->and($note->type)->toBe(CreditNote::TYPE_SALES_RETURN)
        ->and($note->items)->toHaveCount(1)
        ->and((float) $note->items->first()->amount)->toBe(300.0);
});

it('blocks a sales employee from creating a rate difference credit note', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000002');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', rateDifferencePayload($dealer, $product))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    expect(CreditNote::query()->count())->toBe(0);
});

it('lets a manager create a rate difference credit note with calculated amount', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000021');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000022');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson('/api/manager/credit-notes', rateDifferencePayload($dealer, $product))
        ->assertCreated()
        ->assertJsonPath('status', CreditNote::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('amount', 100);

    $note = CreditNote::query()->first();
    expect($note)->not->toBeNull()
        ->and($note->type)->toBe(CreditNote::TYPE_RATE_DIFFERENCE)
        ->and($note->sales_employee_id)->toBe($manager->id)
        ->and($note->items)->toHaveCount(1)
        ->and((float) $note->items->first()->amount)->toBe(100.0);
});

it('blocks a manager from creating a sales return credit note', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000023');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000024');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson('/api/manager/credit-notes', salesReturnPayload($dealer, $product))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    expect(CreditNote::query()->count())->toBe(0);
});

it('lets a manager approve a rate difference they created', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000025');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000026');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson('/api/manager/credit-notes', rateDifferencePayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/credit-notes?status=pending_approval')
        ->assertOk()
        ->assertJsonPath('data.0.id', $note->id);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_APPROVED);
});

it('lists own credit notes and hides other employees notes', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000003');
    $other = creditNoteEmployee(UserRole::Employee, '9300000004');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $otherDealer = creditNoteDealer($other);
    $this->actingAs($other->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($otherDealer, $product, [
            'bill_reference' => 'INV-OTHER',
        ]))
        ->assertCreated();

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/credit-notes?filter=pending_approval')
        ->assertOk()
        ->assertJsonCount(1, 'credit_notes')
        ->assertJsonPath('credit_notes.0.bill_reference', 'INV-1001');
});

it('scopes manager credit note list to direct reports only', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000011');
    $report = creditNoteEmployee(UserRole::Employee, '9300000012');
    $other = creditNoteEmployee(UserRole::Employee, '9300000013');
    $report->update(['reporting_manager_id' => $manager->id]);

    $product = creditNoteProduct();
    $visibleDealer = creditNoteDealer($report);
    $hiddenDealer = creditNoteDealer($other);

    $this->actingAs($report->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($visibleDealer, $product))
        ->assertCreated();

    $this->actingAs($other->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($hiddenDealer, $product, [
            'bill_reference' => 'INV-HIDDEN',
        ]))
        ->assertCreated();

    $visibleId = CreditNote::query()->where('sales_employee_id', $report->id)->value('id');

    $this->actingAs($manager->user, 'sanctum')
        ->getJson('/api/manager/credit-notes?status=pending_approval')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visibleId)
        ->assertJsonPath('counts.pending_approval', 1);
});

it('lets a manager edit a pending team credit note then approve it', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000014');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000015');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->putJson("/api/manager/credit-notes/{$note->id}", salesReturnPayload($dealer, $product, [
            'remarks' => 'Corrected quantity',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'rate' => 150,
                    'reason' => 'Damaged packing',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('amount', 450);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_APPROVED);

    expect($note->fresh()->last_edited_by_role)->toBe(CreditNote::EDITED_BY_ROLE_SALES_MANAGER);
});

it('blocks manager reject without remarks and accepts with remarks', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000016');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000017');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/reject", [])
        ->assertUnprocessable();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/reject", ['remark' => 'Invoice mismatch'])
        ->assertOk();

    $fresh = $note->fresh();
    expect($fresh->status)->toBe(CreditNote::STATUS_REJECTED)
        ->and($fresh->rejected_by_role)->toBe(CreditNote::REJECTED_BY_ROLE_SALES_MANAGER)
        ->and($fresh->rejection_remark)->toBe('Invoice mismatch')
        ->and($fresh->displayStatusLabel())->toBe('Rejected by Sales Manager');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson("/api/employee/credit-notes/{$note->id}")
        ->assertOk()
        ->assertJsonPath('data.rejection_remark', 'Invoice mismatch')
        ->assertJsonStructure(['data' => ['timeline']]);
});

it('lets admin complete an approved credit note and reject another with remarks', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000018');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000019');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();
    $admin = creditNoteAdmin();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $completeNote = CreditNote::query()->first();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product, [
            'bill_reference' => 'INV-REJECT',
        ]))
        ->assertCreated();

    $rejectNote = CreditNote::query()->where('bill_reference', 'INV-REJECT')->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$completeNote->id}/approve")
        ->assertOk();
    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$rejectNote->id}/approve")
        ->assertOk();

    expect(fn () => app(CompleteCreditNote::class)->execute($completeNote->fresh(), $admin))
        ->not->toThrow(Exception::class);
    expect($completeNote->fresh()->status)->toBe(CreditNote::STATUS_COMPLETED);

    app(RejectCreditNoteWithRemarks::class)->execute(
        creditNote: $rejectNote->fresh(),
        actor: $admin,
        remark: 'Duplicate bill reference',
        rejectedByRole: CreditNote::REJECTED_BY_ROLE_ADMIN,
    );

    expect($rejectNote->fresh()->status)->toBe(CreditNote::STATUS_REJECTED)
        ->and($rejectNote->fresh()->rejected_by_role)->toBe(CreditNote::REJECTED_BY_ROLE_ADMIN);
});

it('does not let admin complete a pending credit note', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000020');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();
    $admin = creditNoteAdmin();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    expect(fn () => app(CompleteCreditNote::class)->execute($note, $admin))
        ->toThrow(AuthorizationException::class);
});

it('blocks employee edits after manager approval', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000021');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000022');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk();

    $this->actingAs($employee->user, 'sanctum')
        ->putJson("/api/employee/credit-notes/{$note->id}", salesReturnPayload($dealer, $product))
        ->assertForbidden();
});

it('does not change existing order workflow endpoints', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000023');

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/orders')
        ->assertOk()
        ->assertJsonStructure(['summary', 'recent_orders']);
});

it('accepts an optional supporting document on create', function () {
    Storage::fake('public');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000024');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $payload = salesReturnPayload($dealer, $product);
    $payload['supporting_document'] = UploadedFile::fake()->image('return.jpg');

    $this->actingAs($employee->user, 'sanctum')
        ->post('/api/employee/credit-notes', $payload)
        ->assertCreated();

    $note = CreditNote::query()->first();
    expect($note->supporting_document_path)->not->toBeNull();
    Storage::disk('public')->assertExists($note->supporting_document_path);
});
