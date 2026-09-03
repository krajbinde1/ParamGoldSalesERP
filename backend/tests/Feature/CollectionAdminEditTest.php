<?php

use App\Actions\Collections\UpdateCollectionStatus;
use App\Actions\Employees\CreateEmployeeWithUserAccount;
use App\Enums\UserRole;
use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\Collections\Pages\ListCollections;
use App\Filament\Resources\Collections\Pages\ViewCollection;
use App\Filament\Widgets\AdminDirectorCollectionOutstandingWidget;
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

it('shows status edit beside view for admin and hides it from managers', function (): void {
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

it('lets admin change only status from the edit popup and writes an audit trail', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200002');
    $dealer = collectionEditDealer($employee, 'Audit Dealer');
    $collection = collectionEditRecord($dealer, $employee, [
        'status' => Collection::STATUS_NOT_RECEIVED,
        'amount' => 12000,
        'remarks' => 'Keep this remark',
        'receipt_no' => 'RCP-KEEP-1',
    ]);
    $originalAmount = (float) $collection->amount;
    $originalDate = $collection->collection_date->toDateString();

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->callTableAction('edit', $collection, [
            'status' => Collection::STATUS_RECEIVED,
        ])
        ->assertHasNoTableActionErrors();

    $collection->refresh();
    $audit = CollectionAudit::query()->where('collection_id', $collection->id)->first();

    expect($collection->status)->toBe(Collection::STATUS_RECEIVED)
        ->and((float) $collection->amount)->toBe($originalAmount)
        ->and($collection->collection_date->toDateString())->toBe($originalDate)
        ->and($collection->remarks)->toBe('Keep this remark')
        ->and($collection->receipt_no)->toBe('RCP-KEEP-1')
        ->and($audit)->not->toBeNull()
        ->and((int) $audit->changed_by)->toBe((int) $admin->id)
        ->and(collect($audit->auditRows())->pluck('label')->all())->toBe(['Status']);
});

it('does nothing when the selected status is unchanged', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200003');
    $dealer = collectionEditDealer($employee, 'Unchanged Status Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['status' => Collection::STATUS_RECEIVED]);
    $entryId = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->where('source_id', $collection->id)
        ->value('id');

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_RECEIVED, $admin);

    expect($collection->fresh()->status)->toBe(Collection::STATUS_RECEIVED)
        ->and(CollectionAudit::query()->where('collection_id', $collection->id)->count())->toBe(0)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and((int) DealerTallyEntry::query()->where('source_id', $collection->id)->value('id'))->toBe((int) $entryId);
});

it('posts one ledger credit when status becomes received', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200004');
    $dealer = collectionEditDealer($employee, 'To Received Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 9000, 'status' => Collection::STATUS_NOT_RECEIVED]);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_RECEIVED, $admin);
    app(UpdateCollectionStatus::class)->execute($collection->fresh(), Collection::STATUS_RECEIVED, $admin);

    expect($collection->fresh()->status)->toBe(Collection::STATUS_RECEIVED)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and((float) DealerTallyEntry::query()->where('source_id', $collection->id)->value('credit'))->toBe(9000.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-9000.0);
});

it('removes the ledger credit when received is changed to not received', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200005');
    $dealer = collectionEditDealer($employee, 'To Not Received Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 20000, 'status' => Collection::STATUS_RECEIVED]);

    expect(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-20000.0);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_NOT_RECEIVED, $admin, 'Not received at office');

    expect($collection->fresh()->status)->toBe(Collection::STATUS_NOT_RECEIVED)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);
});

it('removes the ledger credit when received is changed to rejected', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200006');
    $dealer = collectionEditDealer($employee, 'To Rejected Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 15000, 'status' => Collection::STATUS_RECEIVED]);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_REJECTED, $admin, 'Rejected by accounts');

    expect($collection->fresh()->status)->toBe(Collection::STATUS_REJECTED)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);
});

it('does not create a ledger effect between not received and rejected', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200007');
    $dealer = collectionEditDealer($employee, 'No Ledger Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 8000, 'status' => Collection::STATUS_NOT_RECEIVED]);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_REJECTED, $admin, 'Rejected after review');
    expect(DealerTallyEntry::query()->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);

    app(UpdateCollectionStatus::class)->execute($collection->fresh(), Collection::STATUS_NOT_RECEIVED, $admin, 'Still not received');
    expect($collection->fresh()->status)->toBe(Collection::STATUS_NOT_RECEIVED)
        ->and(DealerTallyEntry::query()->where('source_id', $collection->id)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(0.0);
});

it('posts one ledger credit when rejected is changed to received', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200008');
    $dealer = collectionEditDealer($employee, 'Rejected To Received Dealer');
    $collection = collectionEditRecord($dealer, $employee, ['amount' => 11000, 'status' => Collection::STATUS_REJECTED]);

    app(UpdateCollectionStatus::class)->execute($collection, Collection::STATUS_RECEIVED, $admin);

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(-11000.0);

    app(DealerLedgerPostingService::class)->syncReceivedCollection($collection->fresh());

    expect(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1);
});

it('requires a remark when admin sets not received or rejected and shows it on the collection view', function (): void {
    $admin = collectionEditAdmin();
    $employee = collectionEditEmployee('9811200009');
    $dealer = collectionEditDealer($employee, 'Remark Required Dealer');
    $collection = collectionEditRecord($dealer, $employee, [
        'status' => Collection::STATUS_RECEIVED,
        'admin_remark' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->callTableAction('edit', $collection, [
            'status' => Collection::STATUS_NOT_RECEIVED,
        ])
        ->assertHasTableActionErrors(['admin_remark']);

    expect($collection->fresh()->status)->toBe(Collection::STATUS_RECEIVED)
        ->and($collection->fresh()->admin_remark)->toBeNull();

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->callTableAction('edit', $collection, [
            'status' => Collection::STATUS_REJECTED,
            'admin_remark' => '   ',
        ])
        ->assertHasTableActionErrors(['admin_remark']);

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->callTableAction('edit', $collection, [
            'status' => Collection::STATUS_NOT_RECEIVED,
            'admin_remark' => 'Cash not deposited',
        ])
        ->assertHasNoTableActionErrors();

    $collection->refresh();

    expect($collection->status)->toBe(Collection::STATUS_NOT_RECEIVED)
        ->and($collection->admin_remark)->toBe('Cash not deposited')
        ->and(collect($collection->audits()->first()?->auditRows() ?? [])->pluck('label')->all())
        ->toContain('Status')
        ->toContain('Status Remark');

    Livewire::actingAs($admin)
        ->test(ViewCollection::class, ['record' => $collection->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Not Received')
        ->assertSee('Status Remark')
        ->assertSee('Cash not deposited');

    Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->callTableAction('edit', $collection, [
            'status' => Collection::STATUS_RECEIVED,
        ])
        ->assertHasNoTableActionErrors();

    expect($collection->fresh()->status)->toBe(Collection::STATUS_RECEIVED)
        ->and($collection->fresh()->admin_remark)->toBe('Cash not deposited');
});

it('lists newest collection entries first and shows the dashboard collection cards for admin', function (): void {
    $admin = collectionEditAdmin();
    $manager = collectionEditManager();
    $employee = collectionEditEmployee('9811200099');
    $dealer = collectionEditDealer($employee, 'Newest First Dealer');
    $older = collectionEditRecord($dealer, $employee, ['receipt_no' => 'RCP-OLD-1']);
    $newer = collectionEditRecord($dealer, $employee, ['receipt_no' => 'RCP-NEW-1']);

    $older->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $newer->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $adminPage = Livewire::actingAs($admin)
        ->test(ListCollections::class)
        ->assertSuccessful()
        ->assertSeeLivewire(AdminDirectorCollectionOutstandingWidget::class);

    expect($adminPage->instance()->getFilteredSortedTableQuery()->pluck('id')->all())
        ->toBe([$newer->id, $older->id]);

    Livewire::actingAs($admin)
        ->test(AdminDirectorCollectionOutstandingWidget::class)
        ->assertSuccessful()
        ->assertSee("Today's Collection")
        ->assertSee('This Month Collection')
        ->assertSee('Total Outstanding')
        ->assertSee('High Outstanding Dealers');

    $managerPage = Livewire::actingAs($manager)
        ->test(ListCollections::class)
        ->assertSuccessful()
        ->assertDontSeeLivewire(AdminDirectorCollectionOutstandingWidget::class);

    expect($managerPage->instance()->getFilteredSortedTableQuery()->pluck('id')->all())
        ->toBe([$newer->id, $older->id]);
});

it('uses green orange and red status colors', function (): void {
    expect(Collection::statusColor(Collection::STATUS_RECEIVED))->toBe('success')
        ->and(Collection::statusColor(Collection::STATUS_NOT_RECEIVED))->toBe('warning')
        ->and(Collection::statusColor(Collection::STATUS_REJECTED))->toBe('danger');
});
