<?php

use App\Actions\CreditNotes\CompleteCreditNote;
use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Models\CreditNote;
use App\Models\Dealer;
use App\Models\Employee;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\TallyOutboundVoucher;
use App\Models\User;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Dealers\DealerLedgerService;
use App\Services\Inventory\OrderDispatchStockService;
use App\Services\TallySync\TallyOutboundEnqueueService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        'move_to' => CreditNote::MOVE_TO_FACTORY,
        'dealer_id' => $dealer->id,
        'bill_reference' => 'INV-1001',
        'credit_note_date' => now('Asia/Kolkata')->toDateString(),
        'remarks' => 'Customer returned damaged stock',
        'items' => [
            [
                'product_id' => $product->id,
                'case_quantity' => 1,
                'rate_per_no' => 150,
                'reason' => 'Damaged packing',
            ],
        ],
    ], $overrides);
}

function salesReturnExpectedAmount(Product $product, int $cases = 1): float
{
    $quantity = $cases * (int) $product->nos_per_case;
    $base = $quantity * (float) $product->dealer_price;
    $gst = $base * ((float) $product->gst_percentage) / 100;

    return round($base + $gst, 2);
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

    $expected = salesReturnExpectedAmount($product);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated()
        ->assertJsonPath('status', CreditNote::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('amount', (int) $expected);

    $note = CreditNote::query()->first();
    expect($note)->not->toBeNull()
        ->and($note->credit_note_no)->toStartWith('CN')
        ->and($note->type)->toBe(CreditNote::TYPE_SALES_RETURN)
        ->and($note->move_to)->toBe(CreditNote::MOVE_TO_FACTORY)
        ->and($note->credit_note_date->toDateString())->toBe(now('Asia/Kolkata')->toDateString())
        ->and($note->items)->toHaveCount(1)
        ->and((float) $note->items->first()->amount)->toBe($expected)
        ->and((int) $note->items->first()->case_quantity)->toBe(1)
        ->and((int) $note->items->first()->total_quantity_nos)->toBe(20);
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
                    'case_quantity' => 2,
                    'rate_per_no' => 150,
                    'reason' => 'Damaged packing',
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('amount', (int) salesReturnExpectedAmount($product, 2));

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL);

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

    $production = creditNoteEmployee(UserRole::ProductionSupervisor, '9300000030');
    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/credit-notes/{$completeNote->id}/approve")
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
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL);

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

it('requires move to factory or dealer on a sales return', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000101');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $payload = salesReturnPayload($dealer, $product);
    unset($payload['move_to']);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['move_to']);
});

it('sends a factory sales return to production then posts stock only once after production approval', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000102');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000103');
    $production = creditNoteEmployee(UserRole::ProductionSupervisor, '9300000104');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();
    $product->update([
        'current_finished_stock' => 5,
        'weighted_average_cost' => 80,
    ]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_PENDING_PRODUCTION_APPROVAL);

    expect((float) $product->fresh()->current_finished_stock)->toBe(5.0);

    $this->actingAs($production->user, 'sanctum')
        ->getJson('/api/production/credit-notes')
        ->assertOk()
        ->assertJsonPath('data.0.id', $note->id);

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_APPROVED);

    expect((float) $product->fresh()->current_finished_stock)->toBe(25.0)
        ->and($note->fresh()->stock_posted_at)->not->toBeNull();

    app(\App\Services\CreditNotes\CreditNoteStockService::class)
        ->postFactoryReturn($note->fresh(), $production->user);

    expect((float) $product->fresh()->current_finished_stock)->toBe(25.0)
        ->and(StockLedger::query()
            ->where('reference_type', CreditNote::class)
            ->where('reference_id', $note->id)
            ->where('transaction_type', StockTransactionType::Return)
            ->count())->toBe(1);
});

it('does not change factory stock when production rejects a sales return', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000105');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000106');
    $production = creditNoteEmployee(UserRole::ProductionSupervisor, '9300000107');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();
    $product->update(['current_finished_stock' => 8, 'weighted_average_cost' => 80]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    $note = CreditNote::query()->first();

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk();

    $this->actingAs($production->user, 'sanctum')
        ->postJson("/api/production/credit-notes/{$note->id}/reject", [
            'remark' => 'Damaged and not usable',
        ])
        ->assertOk();

    expect($note->fresh()->status)->toBe(CreditNote::STATUS_REJECTED)
        ->and($note->fresh()->rejected_by_role)->toBe(CreditNote::REJECTED_BY_ROLE_PRODUCTION_MANAGER)
        ->and($note->fresh()->stock_posted_at)->toBeNull()
        ->and((float) $product->fresh()->current_finished_stock)->toBe(8.0)
        ->and(StockLedger::query()->where('reference_id', $note->id)->count())->toBe(0);
});

it('creates a linked destination dealer order that skips production and never posts factory stock', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000108');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000109');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $returning = creditNoteDealer($employee);
    $destination = Dealer::query()->create([
        'firm_name' => 'Destination Dealer '.$employee->id,
        'owner_name' => 'Owner Two',
        'mobile' => '97'.str_pad((string) $employee->id, 8, '7', STR_PAD_LEFT),
        'address' => '456 Other Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411002',
        'village' => 'Other Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
    $product = creditNoteProduct();
    $product->update(['current_finished_stock' => 12, 'weighted_average_cost' => 80]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($returning, $product, [
            'move_to' => CreditNote::MOVE_TO_DEALER,
            'destination_dealer_id' => $destination->id,
            'bill_reference' => 'INV-DEALER-1',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.move_to', CreditNote::MOVE_TO_DEALER)
        ->assertJsonPath('data.destination_dealer.id', $destination->id);

    $note = CreditNote::query()->first();
    $linked = Order::query()
        ->where('source_credit_note_id', $note->id)
        ->where('credit_note_link_role', Order::CREDIT_NOTE_LINK_DESTINATION)
        ->first();

    expect($linked)->not->toBeNull()
        ->and($linked->dealer_id)->toBe($destination->id)
        ->and($linked->status)->toBe(Order::STATUS_PENDING_APPROVAL)
        ->and($note->linked_order_id)->toBe($linked->id)
        ->and($linked->items)->toHaveCount(1)
        ->and((int) $linked->items->first()->case_quantity)->toBe(1);

    app(\App\Services\CreditNotes\SalesReturnTransferOrderService::class)
        ->syncLinkedOrder($note->fresh(['items', 'dealer', 'destinationDealer']));

    expect(Order::query()->where('source_credit_note_id', $note->id)->count())->toBe(2);

    $this->actingAs($manager->user, 'sanctum')
        ->postJson("/api/manager/credit-notes/{$note->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', CreditNote::STATUS_APPROVED);

    $linked->refresh();
    expect($linked->status)->toBe(Order::STATUS_PENDING_FOR_BILLING)
        ->and($linked->sent_for_bill_at)->not->toBeNull()
        ->and((float) $product->fresh()->current_finished_stock)->toBe(12.0)
        ->and($note->fresh()->stock_posted_at)->toBeNull();

    $this->actingAs($manager->user, 'sanctum')
        ->getJson("/api/manager/credit-notes/{$note->id}")
        ->assertOk()
        ->assertJsonPath('data.dealer.firm_name', $returning->firm_name)
        ->assertJsonPath('data.destination_dealer.firm_name', $destination->firm_name)
        ->assertJsonPath('data.linked_order.order_no', $linked->order_no)
        ->assertJsonPath('data.bill_reference', 'INV-DEALER-1');

    expect($note->fresh()->dealer_id)->toBe($returning->id)
        ->and($linked->dealer_id)->toBe($destination->id)
        ->and($note->linked_order_id)->toBe($linked->id)
        ->and($note->dealer_id)->not->toBe($linked->dealer_id);
});

it('creates linked source and destination orders for one move to dealer without double counting', function () {
    $manager = creditNoteEmployee(UserRole::Manager, '9300000110');
    $employee = creditNoteEmployee(UserRole::Employee, '9300000111');
    $employee->update(['reporting_manager_id' => $manager->id]);
    $returning = creditNoteDealer($employee);
    $destinationDealer = Dealer::query()->create([
        'firm_name' => 'Destination Dealer '.$employee->id,
        'owner_name' => 'Owner Two',
        'mobile' => '96'.str_pad((string) $employee->id, 8, '6', STR_PAD_LEFT),
        'address' => '456 Other Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411002',
        'village' => 'Other Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
    $product = creditNoteProduct();
    $product->update(['current_finished_stock' => 100, 'weighted_average_cost' => 80]);

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($returning, $product, [
            'move_to' => CreditNote::MOVE_TO_DEALER,
            'destination_dealer_id' => $destinationDealer->id,
            'bill_reference' => 'INV-LINKED-1',
        ]))
        ->assertCreated();

    $note = CreditNote::query()->firstOrFail();
    $billing = Order::query()
        ->where('credit_note_link_role', Order::CREDIT_NOTE_LINK_DESTINATION)
        ->firstOrFail();
    $source = Order::query()
        ->where('credit_note_link_role', Order::CREDIT_NOTE_LINK_SOURCE)
        ->firstOrFail();

    expect($billing->source_credit_note_id)->toBe($note->id)
        ->and($source->source_credit_note_id)->toBe($note->id)
        ->and($billing->dealer_id)->toBe($destinationDealer->id)
        ->and($source->dealer_id)->toBe($returning->id)
        ->and($note->fresh()->linked_order_id)->toBe($billing->id)
        ->and($note->fresh()->linked_source_order_id)->toBe($source->id)
        ->and($billing->paired_order_id)->toBe($source->id)
        ->and($source->paired_order_id)->toBe($billing->id)
        ->and($billing->sales_employee_id)->toBe($employee->id)
        ->and($source->sales_employee_id)->toBe($employee->id)
        ->and($source->status)->toBe(Order::STATUS_CREDIT_NOTE_RECORD)
        ->and($billing->status)->toBe(Order::STATUS_PENDING_APPROVAL)
        ->and($billing->items)->toHaveCount(1)
        ->and($source->items)->toHaveCount(1)
        ->and((int) $billing->items->first()->product_id)->toBe($product->id)
        ->and((int) $source->items->first()->product_id)->toBe($product->id)
        ->and((int) $billing->items->first()->case_quantity)->toBe((int) $source->items->first()->case_quantity)
        ->and((float) $billing->grand_total)->toBe((float) $source->grand_total)
        ->and($source->isBilledReceivable())->toBeFalse()
        ->and($source->canBeBilled())->toBeFalse();

    app(\App\Services\CreditNotes\SalesReturnTransferOrderService::class)
        ->syncLinkedOrder($note->fresh(['items', 'dealer', 'destinationDealer']));

    expect(Order::query()->where('source_credit_note_id', $note->id)->count())->toBe(2);

    $sales = app(DirectorDashboardDataService::class)->dashboardSalesTotal(
        now('Asia/Kolkata')->startOfDay(),
        now('Asia/Kolkata')->endOfDay(),
    );
    expect($sales)->toBe((float) $billing->grand_total);

    DB::table('orders')->where('id', $source->id)->update(['status' => Order::STATUS_PENDING_APPROVAL]);
    $source->refresh();
    expect(app(DealerLedgerService::class)->getUnbilledOrders($returning->fresh()))->toBe(0.0)
        ->and(app(DealerLedgerService::class)->getUnbilledOrders($destinationDealer->fresh()))->toBe((float) $billing->grand_total);

    DB::table('orders')->where('id', $source->id)->update(['status' => Order::STATUS_BILLED]);
    DB::table('orders')->where('id', $billing->id)->update(['status' => Order::STATUS_BILLED]);
    $source->refresh();
    $billing->refresh();

    expect($source->fresh()->isBilledReceivable())->toBeFalse()
        ->and($billing->fresh()->isBilledReceivable())->toBeTrue()
        ->and(app(DealerLedgerService::class)->getTotalBilledSales($destinationDealer->fresh()))->toBe((float) $billing->grand_total)
        ->and(app(DealerLedgerService::class)->getTotalBilledSales($returning->fresh()))->toBe(0.0);

    expect(app(DealerLedgerPostingService::class)->syncDispatchedOrder($source->fresh()))->toBeNull();
    expect(app(DealerLedgerPostingService::class)->syncDispatchedOrder($billing->fresh()))->not->toBeNull();
    expect(app(DealerLedgerPostingService::class)->syncDispatchedOrder($billing->fresh()))->not->toBeNull();
    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->count())->toBe(1);

    expect(app(TallyOutboundEnqueueService::class)->queueBilledOrder($source->fresh()))->toBeNull();
    expect(app(TallyOutboundEnqueueService::class)->queueBilledOrder($billing->fresh()))->not->toBeNull();
    expect(app(TallyOutboundEnqueueService::class)->queueBilledOrder($billing->fresh()))->not->toBeNull();
    expect(TallyOutboundVoucher::query()->where('source_type', TallyOutboundVoucher::SOURCE_SALES_ORDER)->count())->toBe(1);

    DB::table('orders')->where('id', $source->id)->update([
        'status' => Order::STATUS_DISPATCHED,
        'dispatched_at' => now(),
    ]);
    $source->refresh();
    app(OrderDispatchStockService::class)->postForDispatchedOrder($source);
    expect((float) $product->fresh()->current_finished_stock)->toBe(100.0);

    DB::table('orders')->where('id', $billing->id)->update([
        'status' => Order::STATUS_DISPATCHED,
        'dispatched_at' => now(),
    ]);
    $billing->refresh();
    app(OrderDispatchStockService::class)->postForDispatchedOrder($billing->fresh());
    $afterDispatch = (float) $product->fresh()->current_finished_stock;
    expect($afterDispatch)->toBeLessThan(100.0);
    app(OrderDispatchStockService::class)->postForDispatchedOrder($billing->fresh());
    expect((float) $product->fresh()->current_finished_stock)->toBe($afterDispatch);
    expect(StockLedger::query()->where('reference_type', Order::class)->where('reference_id', $source->id)->count())->toBe(0)
        ->and(StockLedger::query()->where('reference_type', Order::class)->where('reference_id', $billing->id)->count())->toBeGreaterThan(0);

    $normal = Order::query()->create([
        'order_no' => 'ORD-NORMAL-100',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $destinationDealer->id,
        'sales_employee_id' => $employee->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 100,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 100,
    ]);

    expect($normal->source_credit_note_id)->toBeNull()
        ->and($normal->credit_note_link_role)->toBeNull()
        ->and(app(DirectorDashboardDataService::class)->dashboardSalesTotal(
            now('Asia/Kolkata')->startOfDay(),
            now('Asia/Kolkata')->endOfDay(),
        ))->toBe(round((float) $billing->grand_total + 100, 2));

    $this->actingAs($employee->user, 'sanctum')
        ->getJson('/api/employee/orders')
        ->assertOk()
        ->assertJsonPath('summary.total_orders', 2);
});

it('rolls back the credit note and both orders when the source record fails', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000112');
    $returning = creditNoteDealer($employee);
    $destinationDealer = Dealer::query()->create([
        'firm_name' => 'Rollback Dealer '.$employee->id,
        'owner_name' => 'Owner Two',
        'mobile' => '95'.str_pad((string) $employee->id, 8, '5', STR_PAD_LEFT),
        'address' => '456 Other Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411002',
        'village' => 'Other Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
    $product = creditNoteProduct();

    $created = 0;
    Order::created(function () use (&$created): void {
        $created++;
        if ($created >= 2) {
            throw new RuntimeException('second order failed');
        }
    });

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($returning, $product, [
            'move_to' => CreditNote::MOVE_TO_DEALER,
            'destination_dealer_id' => $destinationDealer->id,
        ]));

    expect(CreditNote::query()->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0);
});

it('does not create an order for a factory sales return', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000113');
    $dealer = creditNoteDealer($employee);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($dealer, $product))
        ->assertCreated();

    expect(Order::query()->count())->toBe(0)
        ->and(CreditNote::query()->firstOrFail()->linked_order_id)->toBeNull()
        ->and(CreditNote::query()->firstOrFail()->linked_source_order_id)->toBeNull();
});

it('replaces the unique credit note index without dropping the foreign key or existing rows', function () {
    $migration = include database_path('migrations/2026_09_28_214500_add_credit_note_order_links.php');

    $migration->up();

    $migration->down();

    $employee = creditNoteEmployee(UserRole::Employee, '9300000114');
    $dealer = creditNoteDealer($employee);
    $note = CreditNote::query()->create([
        'type' => CreditNote::TYPE_SALES_RETURN,
        'move_to' => CreditNote::MOVE_TO_DEALER,
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'bill_reference' => 'INV-SCHEMA-1',
        'credit_note_date' => now('Asia/Kolkata')->toDateString(),
        'amount' => 10,
        'status' => CreditNote::STATUS_PENDING_APPROVAL,
    ]);
    $order = Order::query()->create([
        'order_no' => 'PG-SCHEMA-0001',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'source_credit_note_id' => $note->id,
        'status' => Order::STATUS_PENDING_APPROVAL,
        'payment_type' => 'Credit',
        'subtotal' => 10,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 10,
    ]);

    expect(Schema::hasIndex('orders', 'orders_source_credit_note_id_unique'))->toBeTrue()
        ->and(creditNoteForeignKeyExists())->toBeTrue();

    $migration->up();

    $kept = Order::query()->findOrFail($order->id);
    expect(Schema::hasIndex('orders', 'orders_source_credit_note_id_unique'))->toBeFalse()
        ->and(Schema::hasIndex('orders', 'orders_source_credit_note_id_index'))->toBeTrue()
        ->and(Schema::hasIndex('orders', 'orders_credit_note_link_unique'))->toBeTrue()
        ->and(creditNoteForeignKeyExists())->toBeTrue()
        ->and($kept->order_no)->toBe('PG-SCHEMA-0001')
        ->and($kept->credit_note_link_role)->toBe(Order::CREDIT_NOTE_LINK_DESTINATION)
        ->and((float) $kept->grand_total)->toBe(10.0)
        ->and(CreditNote::query()->whereKey($note->id)->exists())->toBeTrue();

    if (DB::getDriverName() !== 'sqlite') {
        expect($kept->source_credit_note_id)->toBe($note->id);
    }

    Order::query()->create([
        'order_no' => 'PG-SCHEMA-0002',
        'order_date' => now('Asia/Kolkata')->toDateString(),
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'source_credit_note_id' => $note->id,
        'credit_note_link_role' => Order::CREDIT_NOTE_LINK_SOURCE,
        'status' => Order::STATUS_CREDIT_NOTE_RECORD,
        'payment_type' => 'Credit',
        'subtotal' => 10,
        'discount_amount' => 0,
        'gst_amount' => 0,
        'grand_total' => 10,
    ]);

    $sourceOrder = Order::query()->where('order_no', 'PG-SCHEMA-0002')->firstOrFail();

    expect(Order::query()->count())->toBe(2)
        ->and($sourceOrder->source_credit_note_id)->toBe($note->id)
        ->and($sourceOrder->credit_note_link_role)->toBe(Order::CREDIT_NOTE_LINK_SOURCE)
        ->and(CreditNote::query()->whereKey($note->id)->exists())->toBeTrue();

    $migration->up();

    expect(Order::query()->count())->toBe(2);
});

it('does not delete orders when rollback cannot restore the old unique index', function () {
    $employee = creditNoteEmployee(UserRole::Employee, '9300000115');
    $returning = creditNoteDealer($employee);
    $destination = Dealer::query()->create([
        'firm_name' => 'Schema Destination '.$employee->id,
        'owner_name' => 'Owner Two',
        'mobile' => '94'.str_pad((string) $employee->id, 8, '4', STR_PAD_LEFT),
        'address' => '456 Other Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411002',
        'village' => 'Other Village',
        'status' => true,
        'assigned_employee_id' => $employee->id,
    ]);
    $product = creditNoteProduct();

    $this->actingAs($employee->user, 'sanctum')
        ->postJson('/api/employee/credit-notes', salesReturnPayload($returning, $product, [
            'move_to' => CreditNote::MOVE_TO_DEALER,
            'destination_dealer_id' => $destination->id,
        ]))
        ->assertCreated();

    $before = Order::query()->count();
    $migration = include database_path('migrations/2026_09_28_214500_add_credit_note_order_links.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Order::query()->count())->toBe($before)
        ->and(CreditNote::query()->count())->toBe(1)
        ->and(Schema::hasColumn('orders', 'credit_note_link_role'))->toBeTrue();
});

function creditNoteForeignKeyExists(): bool
{
    foreach (Schema::getForeignKeys('orders') as $foreignKey) {
        $columns = $foreignKey['columns'] ?? [];
        if ($columns === ['source_credit_note_id'] && ($foreignKey['foreign_table'] ?? null) === 'credit_notes') {
            return true;
        }
    }

    return false;
}
