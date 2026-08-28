<?php

use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Collections\Pages\ViewCollection;
use App\Models\Collection;
use App\Models\CollectionAudit;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\Employee;
use App\Models\User;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use Livewire\Livewire;

function collectionEditAdmin(): User
{
    return User::query()->create([
        'name' => 'Collection Admin',
        'email' => 'collection.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Admin',
    ]);
}

function collectionEditManager(): User
{
    return User::query()->create([
        'name' => 'Collection Manager',
        'email' => 'collection.manager.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Manager->value,
        'job_role' => 'Manager',
    ]);
}

function collectionEditEmployee(string $mobile): Employee
{
    return app(CreateEmployeeWithUserAccount::class)->execute([
        'full_name' => 'Collection Employee '.$mobile,
        'mobile' => $mobile,
        'email' => $mobile.'@example.com',
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

function collectionEditDealer(Employee $employee, string $firmName): Dealer
{
    return Dealer::query()->create([
        'firm_name' => $firmName,
        'owner_name' => 'Owner',
        'mobile' => '98'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'village' => 'Wagholi',
        'pincode' => '411001',
        'status' => true,
        'assigned_employee_id' => $employee->id,
        'opening_balance' => 0,
    ]);
}

function collectionEditRecord(Dealer $dealer, Employee $employee, array $overrides = []): Collection
{
    $suffix = (string) random_int(1000, 999999);

    return Collection::query()->create(array_merge([
        'receipt_no' => 'RCP-ED-'.$suffix,
        'collection_date' => '2026-04-18',
        'dealer_id' => $dealer->id,
        'sales_employee_id' => $employee->id,
        'amount' => 20000,
        'status' => Collection::STATUS_RECEIVED,
        'payment_mode' => 'Cash',
        'transaction_number' => 'TXN-ED-'.$suffix,
        'remarks' => 'Original remark',
    ], $overrides));
}

it('shows edit beside view for admin and hides it from managers', function (): void {
    $admin = collectionEditAdmin();
    $manager = collectionEditManager();
    $employee = collectionEditEmployee('9811200001');
    $dealer = collectionEditDealer($employee, 'Edit Visible Dealer');
    $collection = collectionEditRecord($dealer, $employee);

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->assertTableActionVisible('view', $collection)
        ->assertTableActionVisible('edit', $collection);

    Livewire::actingAs($admin)
        ->test(ViewCollection::class, ['record' => $collection->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('edit');

    Livewire::actingAs($manager)
        ->test(ListCollections::class)
        ->assertTableActionVisible('view', $collection)
        ->assertTableActionHidden('edit', $collection);

    Livewire::actingAs($manager)
        ->test(EditCollection::class, ['record' => $collection->getRouteKey()])
        ->assertForbidden();
});

it('lets admin edit collection fields and writes an audit trail', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200002');
    $otherEmployee = collectionEditEmployee('9811200003');
    $dealer = collectionEditDealer($employee, 'Audit Dealer');
    $collection = collectionEditRecord($dealer, $employee, [
        'status' => Collection::STATUS_PENDING,
        'amount' => 12000,
    ]);

    Livewire::actingAs($admin)
        ->test(EditCollection::class, ['record' => $collection->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'collection_date' => '2026-05-02',
            'dealer_id' => $dealer->id,
            'sales_employee_id' => $otherEmployee->id,
            'amount' => 15500.5,
            'status' => Collection::STATUS_PENDING,
            'receipt_no' => 'RCP-EDITED-1',
            'payment_mode' => 'UPI',
            'transaction_number' => 'UPI-9988',
            'remarks' => 'Updated by admin',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $collection->refresh();
    $audit = CollectionAudit::query()->where('collection_id', $collection->id)->first();

    expect($collection->collection_date->toDateString())->toBe('2026-05-02')
        ->and((int) $collection->sales_employee_id)->toBe((int) $otherEmployee->id)
        ->and((float) $collection->amount)->toBe(15500.5)
        ->and($collection->receipt_no)->toBe('RCP-EDITED-1')
        ->and($collection->payment_mode)->toBe('UPI')
        ->and($collection->transaction_number)->toBe('UPI-9988')
        ->and($collection->remarks)->toBe('Updated by admin')
        ->and($audit)->not->toBeNull()
        ->and((int) $audit->changed_by)->toBe((int) $admin->id)
        ->and(collect($audit->auditRows())->pluck('label')->all())->toContain('Collection Date', 'Sales Employee', 'Amount', 'Receipt No.', 'Payment Mode', 'Transaction / Reference No.', 'Remark');
});

it('removes the dealer ledger credit when a received collection is marked not received', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200004');
    $dealer = collectionEditDealer($employee, 'Reverse Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_RECEIVED]);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-20000.0);

    Livewire::actingAs($admin)
        ->test(EditCollection::class, ['record' => $collection->getRouteKey()])
        ->fillForm([
            'status' => Collection::STATUS_NOT_RECEIVED,
            'admin_remark' => 'Cheque bounced',
            'amount' => 20000,
            'dealer_id' => $dealer->id,
            'collection_date' => '2026-04-18',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($collection->fresh()->status)->toBe(Collection::STATUS_NOT_RECEIVED)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);
});

it('updates the existing ledger credit when a received collection amount is edited', function (): void {
    $employee = collectionEditEmployee('9811200005');
    $dealer = collectionEditDealer($employee, 'Amount Edit Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_RECEIVED]);
    $entryId = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->value('id');

    $collection->update(['amount' => 17500]);

    $entries = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and((int) $entries->first()->id)->toBe((int) $entryId)
        ->and((float) $entries->first()->credit)->toBe(17500.0)
        ->and((float) $entries->first()->debit)->toBe(0.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-17500.0);
});

it('moves the ledger credit when the dealer is changed on a received collection', function (): void {
    $employee = collectionEditEmployee('9811200006');
    $oldDealer = collectionEditDealer($employee, 'Old Collection Dealer');
    $newDealer = collectionEditDealer($employee, 'New Collection Dealer');
    $collection = collectionEditRecord($oldDealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_RECEIVED]);
    $entryId = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->value('id');

    $collection->update(['dealer_id' => $newDealer->id]);

    $entry = DealerTallyEntry::query()->find($entryId);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and((int) $entry->dealer_id)->toBe((int) $newDealer->id)
        ->and((float) $entry->credit)->toBe(20000.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($oldDealer->fresh()))->toBe(0.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($newDealer->fresh()))->toBe(-20000.0);
});

it('does not apply a ledger credit when the dealer is changed and status is not received', function (): void {
    $employee = collectionEditEmployee('9811200007');
    $oldDealer = collectionEditDealer($employee, 'Old Pending Dealer');
    $newDealer = collectionEditDealer($employee, 'New Pending Dealer');
    $collection = collectionEditRecord($oldDealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_RECEIVED]);

    $collection->update([
        'dealer_id' => $newDealer->id,
        'status' => Collection::STATUS_PENDING,
    ]);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($oldDealer->fresh()))->toBe(0.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($newDealer->fresh()))->toBe(0.0);
});

it('posts a single ledger credit when status changes from pending to received', function (): void {
    $employee = collectionEditEmployee('9811200008');
    $dealer = collectionEditDealer($employee, 'Pending To Received Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 9000, 'status' => Collection::STATUS_PENDING]);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0);

    $collection->update(['status' => Collection::STATUS_RECEIVED]);
    $collection->update(['status' => Collection::STATUS_RECEIVED, 'remarks' => 'saved again']);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and((float) DealerTallyEntry::query()->where('source_id', $collection->id)->value('credit'))->toBe(9000.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-9000.0);
});

it('syncs a received collection through the posting service without duplicating the credit', function (): void {
    $employee = collectionEditEmployee('9811200009');
    $dealer = collectionEditDealer($employee, 'Sync Once Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 4000, 'status' => Collection::STATUS_RECEIVED]);
    $service = app(DealerLedgerPostingService::class);

    $service->syncReceivedCollection($collection->fresh());
    $service->syncReceivedCollection($collection->fresh());

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1);
});
