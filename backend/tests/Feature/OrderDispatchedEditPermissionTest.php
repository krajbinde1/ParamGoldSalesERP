<?php

use App\Actions\Orders\AdminRejectOrderEditPermission;
use App\Actions\Orders\ApplyDispatchedOrderTransportCorrection;
use App\Actions\Orders\ApproveOrderEditPermission;
use App\Actions\Orders\BillOrderWithDocument;
use App\Actions\Orders\ConfirmOrderEditPermission;
use App\Actions\Orders\DispatchOrder;
use App\Actions\Orders\RejectOrderEditPermission;
use App\Actions\Orders\RequestOrderEditPermission;
use App\Actions\Orders\SendOrderForBilling;
use App\Enums\UserRole;
use App\Filament\Resources\OrderEditPermissionRequests\OrderEditPermissionRequestResource;
use App\Filament\Resources\OrderEditPermissionRequests\Pages\ListOrderEditPermissionRequests;
use App\Filament\Resources\OrderEditPermissionRequests\Pages\ViewOrderEditPermissionRequest;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderEditPermissionRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function dispatchedEditAdmin(): User
{
    return User::query()->create([
        'name' => 'Web Admin',
        'email' => 'admin.edit.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

function dispatchedEditDirector(): User
{
    return User::query()->create([
        'name' => 'Web Director',
        'email' => 'director.edit.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

function dispatchedEditEmployee(UserRole $role): Employee
{
    static $seq = 0;
    $seq++;
    $mobile = (string) (9300000000 + $seq);

    return orderWorkflowEmployee($role, $mobile);
}

function dispatchedOrderReadyForEdit(): array
{
    Storage::fake('public');

    $employee = dispatchedEditEmployee(UserRole::Employee);
    $manager = dispatchedEditEmployee(UserRole::Manager);
    $production = dispatchedEditEmployee(UserRole::ProductionSupervisor);
    $employee->update(['reporting_manager_id' => $manager->id]);

    $admin = dispatchedEditAdmin();
    $director = dispatchedEditDirector();
    $order = orderWorkflowPending($employee->id);
    $order->approve($manager->user->id);

    $vehicle = Vehicle::query()->create([
        'vehicle_number' => 'MH12ED'.random_int(1000, 9999),
        'vehicle_name' => 'Tata Ace',
        'is_active' => true,
        'created_by' => $production->user->id,
    ]);

    app(SendOrderForBilling::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        vehicleId: $vehicle->id,
        transportChargeType: 'transport_extra',
        transportFreight: 250,
    );

    app(BillOrderWithDocument::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        bill: UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf'),
        billNumber: 'BILL-EDIT-1',
    );

    app(DispatchOrder::class)->execute(
        order: $order->fresh(),
        actor: $production->user,
        remark: 'Loaded',
    );

    return [
        'order' => $order->fresh(),
        'admin' => $admin,
        'director' => $director,
        'vehicle' => $vehicle,
        'production' => $production,
    ];
}

it('locks dispatched orders from admin edits until the director approves a one-time correction', function () {
    $ctx = dispatchedOrderReadyForEdit();
    /** @var Order $order */
    $order = $ctx['order'];
    $admin = $ctx['admin'];
    $director = $ctx['director'];
    $vehicle = $ctx['vehicle'];

    expect($order->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($order->canBeEdited())->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $order))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $order))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('requestDispatchedEdit', $order))->toBeTrue();

    expect(fn () => app(ApplyDispatchedOrderTransportCorrection::class)->execute(
        order: $order,
        actor: $admin,
        vehicleId: $vehicle->id,
        transportChargeType: 'company_transport',
        transportFreight: 40,
    ))->toThrow(AuthorizationException::class);

    $requested = app(RequestOrderEditPermission::class)->execute(
        order: $order,
        actor: $admin,
        reason: 'Wrong vehicle number and transport charges entered at Send for Bill.',
    )['request'];

    expect($requested->status)->toBe(OrderEditPermissionRequest::STATUS_PENDING)
        ->and($order->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($order->fresh()->canBeEdited())->toBeFalse()
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $order->fresh()))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('requestDispatchedEdit', $order->fresh()))->toBeFalse();

    expect(AppNotification::query()
        ->where('user_id', $director->id)
        ->where('type', 'order_edit_permission_requested')
        ->exists())->toBeTrue();

    expect(fn () => app(ApproveOrderEditPermission::class)->execute($requested, $admin))
        ->toThrow(AuthorizationException::class);

    $approved = app(ApproveOrderEditPermission::class)->execute($requested->fresh(), $director)['request'];

    expect($approved->status)->toBe(OrderEditPermissionRequest::STATUS_APPROVED)
        ->and($approved->reviewed_by)->toBe($director->id)
        ->and($approved->admin_reviewed_at)->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($order->fresh()->canBeEdited())->toBeFalse()
        ->and(Gate::forUser($admin)->allows('confirmDispatchedEdit', $order->fresh()))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $order->fresh()))->toBeFalse();

    expect(fn () => app(ApplyDispatchedOrderTransportCorrection::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        vehicleId: $vehicle->id,
        transportChargeType: 'company_transport',
        transportFreight: 40,
    ))->toThrow(AuthorizationException::class);

    $confirmed = app(ConfirmOrderEditPermission::class)->execute($order->fresh(), $admin)['request'];

    expect($confirmed->status)->toBe(OrderEditPermissionRequest::STATUS_ADMIN_APPROVED)
        ->and($confirmed->admin_reviewed_by)->toBe($admin->id)
        ->and($order->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($order->fresh()->canBeEdited())->toBeFalse()
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $order->fresh()))->toBeTrue();

    $otherVehicle = Vehicle::query()->create([
        'vehicle_number' => 'MH14ED'.random_int(1000, 9999),
        'vehicle_name' => 'Eicher',
        'is_active' => true,
        'created_by' => $admin->id,
    ]);

    $originalKeys = collect($order->fresh()->workflowTimeline())->pluck('key')->all();

    $corrected = app(ApplyDispatchedOrderTransportCorrection::class)->execute(
        order: $order->fresh(),
        actor: $admin,
        vehicleId: $otherVehicle->id,
        transportChargeType: 'company_transport',
        transportFreight: 40,
    );

    $fresh = $corrected['order'];
    $used = $corrected['request'];

    expect($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($fresh->vehicle_id)->toBe($otherVehicle->id)
        ->and($fresh->vehicle_number)->toBe($otherVehicle->vehicle_number)
        ->and($fresh->transport_charge_type)->toBe('company_transport')
        ->and((float) $fresh->transport_amount)->toBe(40.0)
        ->and((float) $fresh->grand_total)->toBe(60.0)
        ->and($fresh->canBeEdited())->toBeFalse()
        ->and($used->status)->toBe(OrderEditPermissionRequest::STATUS_USED)
        ->and($used->edited_by)->toBe($admin->id)
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $fresh))->toBeFalse();

    expect($used->auditRows())->toHaveCount(4);
    expect(collect($used->auditRows())->firstWhere('field', 'vehicle_number'))
        ->toMatchArray([
            'label' => 'Vehicle No.',
            'old' => $vehicle->vehicle_number,
            'new' => $otherVehicle->vehicle_number,
        ]);

    $timeline = collect($fresh->fresh()->load('workflowEvents.user')->workflowTimeline());
    $keys = $timeline->pluck('key')->all();

    expect($keys)->toContain('created', 'approved', 'pending_for_billing', 'billed', 'dispatched', 'details_corrected')
        ->and(array_search('details_corrected', $keys, true))->toBeGreaterThan(array_search('dispatched', $keys, true));

    foreach ($originalKeys as $key) {
        expect($keys)->toContain($key);
    }

    $correction = $timeline->firstWhere('key', 'details_corrected');
    expect($correction['label'])->toBe('Order Details Corrected by Admin')
        ->and($correction['completed'])->toBeTrue()
        ->and($correction['remark'])->toContain('Approved by Director: '.$director->name)
        ->and($correction['remark'])->toContain('Edited by Admin: '.$admin->name)
        ->and($correction['remark'])->toContain('Wrong vehicle number and transport charges entered at Send for Bill.');

    expect(fn () => app(ApplyDispatchedOrderTransportCorrection::class)->execute(
        order: $fresh,
        actor: $admin,
        vehicleId: $vehicle->id,
        transportChargeType: 'transport_extra',
        transportFreight: 10,
    ))->toThrow(AuthorizationException::class);

    expect(Gate::forUser($admin)->allows('requestDispatchedEdit', $fresh->fresh()))->toBeTrue();
});

it('rejects an edit request with a director remark and lets admin request again', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $order = $ctx['order'];
    $admin = $ctx['admin'];
    $director = $ctx['director'];

    $request = app(RequestOrderEditPermission::class)->execute(
        order: $order,
        actor: $admin,
        reason: 'Need to fix transport type.',
    )['request'];

    expect(fn () => app(RejectOrderEditPermission::class)->execute($request, $director, 'x'))
        ->toThrow(ValidationException::class);

    $rejected = app(RejectOrderEditPermission::class)->execute(
        request: $request->fresh(),
        actor: $director,
        remark: 'Bill already issued. Do not change charges.',
    )['request'];

    expect($rejected->status)->toBe(OrderEditPermissionRequest::STATUS_REJECTED)
        ->and($rejected->rejection_remark)->toBe('Bill already issued. Do not change charges.')
        ->and($order->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and(Gate::forUser($admin)->allows('correctDispatchedTransport', $order->fresh()))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('requestDispatchedEdit', $order->fresh()))->toBeTrue();
});

it('requires a reason and blocks duplicate open requests', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $order = $ctx['order'];
    $admin = $ctx['admin'];

    expect(fn () => app(RequestOrderEditPermission::class)->execute($order, $admin, 'no'))
        ->toThrow(ValidationException::class);

    app(RequestOrderEditPermission::class)->execute($order, $admin, 'Incorrect vehicle number.');

    expect(fn () => app(RequestOrderEditPermission::class)->execute($order->fresh(), $admin, 'Another correction.'))
        ->toThrow(AuthorizationException::class);
});

it('does not allow production, director, or admin-as-director to request or self-approve', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $order = $ctx['order'];
    $director = $ctx['director'];
    $production = $ctx['production']->user;

    expect(Gate::forUser($director)->allows('requestDispatchedEdit', $order))->toBeFalse()
        ->and(Gate::forUser($production)->allows('requestDispatchedEdit', $order))->toBeFalse()
        ->and(OrderEditPermissionRequestResource::canAccess())->toBeFalse();

    $this->actingAs($director);
    expect(OrderEditPermissionRequestResource::canAccess())->toBeTrue();
});

it('shows the request button on the admin order view for dispatched orders', function () {
    $ctx = dispatchedOrderReadyForEdit();

    Livewire::actingAs($ctx['admin'])
        ->test(ViewOrder::class, ['record' => $ctx['order']->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('requestEditPermission')
        ->assertActionHidden('approveEditPermission')
        ->assertActionHidden('correctDispatchedTransport')
        ->callAction('requestEditPermission', data: [
            'reason' => 'Vehicle number entered incorrectly by Production Manager.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(OrderEditPermissionRequest::query()->where('order_id', $ctx['order']->id)->pending()->exists())->toBeTrue()
        ->and($ctx['order']->fresh()->status)->toBe(Order::STATUS_DISPATCHED);
});

it('lets the director open order edit requests and approve from the resource', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $request = app(RequestOrderEditPermission::class)->execute(
        order: $ctx['order'],
        actor: $ctx['admin'],
        reason: 'Transport charges were entered as extra instead of company transport.',
    )['request'];

    Livewire::actingAs($ctx['director'])
        ->test(ListOrderEditPermissionRequests::class)
        ->assertSuccessful()
        ->assertSee($ctx['order']->shortOrderNo());

    Livewire::actingAs($ctx['director'])
        ->test(ViewOrderEditPermissionRequest::class, [
            'record' => $request->getRouteKey(),
        ])
        ->assertSuccessful()
        ->assertActionVisible('approve')
        ->callAction('approve')
        ->assertHasNoActionErrors();

    expect($request->fresh()->status)->toBe(OrderEditPermissionRequest::STATUS_APPROVED)
        ->and($ctx['order']->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and(Gate::forUser($ctx['admin'])->allows('correctDispatchedTransport', $ctx['order']->fresh()))->toBeFalse()
        ->and(Gate::forUser($ctx['admin'])->allows('confirmDispatchedEdit', $ctx['order']->fresh()))->toBeTrue();
});

it('loads admin orders pages after director approval and keeps the order locked until admin confirms', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $request = app(RequestOrderEditPermission::class)->execute(
        order: $ctx['order'],
        actor: $ctx['admin'],
        reason: 'Vehicle number entered incorrectly by Production Manager.',
    )['request'];

    app(ApproveOrderEditPermission::class)->execute($request->fresh(), $ctx['director']);

    $order = $ctx['order']->fresh();

    expect($order->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($request->fresh()->status)->toBe(OrderEditPermissionRequest::STATUS_APPROVED)
        ->and($order->canBeEdited())->toBeFalse();

    Livewire::actingAs($ctx['admin'])
        ->test(ListOrders::class)
        ->assertSuccessful()
        ->set('activeTab', 'dispatched')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$order])
        ->assertTableActionVisible('approveEditPermission', $order)
        ->assertTableActionVisible('rejectEditPermission', $order)
        ->assertTableActionHidden('correctDispatchedTransport', $order)
        ->assertTableActionHidden('requestEditPermission', $order);

    foreach ([
        'all',
        'pending_approval',
        'reverted_to_manager',
        'approved',
        'on_hold',
        'pending_for_billing',
        'billed',
        'dispatched',
        'rejected',
    ] as $tab) {
        Livewire::actingAs($ctx['admin'])
            ->test(ListOrders::class)
            ->set('activeTab', $tab)
            ->assertSuccessful();
    }

    Livewire::actingAs($ctx['admin'])
        ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('approveEditPermission')
        ->assertActionVisible('rejectEditPermission')
        ->assertActionHidden('correctDispatchedTransport')
        ->callAction('approveEditPermission')
        ->assertHasNoActionErrors();

    $fresh = $order->fresh();
    expect($request->fresh()->status)->toBe(OrderEditPermissionRequest::STATUS_ADMIN_APPROVED)
        ->and($fresh->status)->toBe(Order::STATUS_DISPATCHED)
        ->and($fresh->canBeEdited())->toBeFalse()
        ->and(Gate::forUser($ctx['admin'])->allows('correctDispatchedTransport', $fresh))->toBeTrue();

    Livewire::actingAs($ctx['admin'])
        ->test(ListOrders::class)
        ->set('activeTab', 'dispatched')
        ->assertSuccessful()
        ->assertTableActionVisible('correctDispatchedTransport', $fresh)
        ->assertTableActionHidden('approveEditPermission', $fresh);
});

it('treats existing director-approved requests as waiting for admin confirmation', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $request = app(RequestOrderEditPermission::class)->execute(
        order: $ctx['order'],
        actor: $ctx['admin'],
        reason: 'Need to correct transport type on an already approved request.',
    )['request'];

    app(ApproveOrderEditPermission::class)->execute($request->fresh(), $ctx['director']);

    $request->forceFill([
        'status' => OrderEditPermissionRequest::STATUS_APPROVED,
        'admin_reviewed_by' => null,
        'admin_reviewed_at' => null,
    ])->saveQuietly();

    $order = $ctx['order']->fresh();

    expect($request->fresh()->isAwaitingAdminConfirmation())->toBeTrue()
        ->and(Gate::forUser($ctx['admin'])->allows('confirmDispatchedEdit', $order))->toBeTrue()
        ->and(Gate::forUser($ctx['admin'])->allows('correctDispatchedTransport', $order))->toBeFalse();

    Livewire::actingAs($ctx['admin'])
        ->test(ListOrders::class)
        ->set('activeTab', 'dispatched')
        ->assertSuccessful()
        ->assertTableActionVisible('approveEditPermission', $order)
        ->callTableAction('approveEditPermission', $order)
        ->assertHasNoTableActionErrors();

    expect($request->fresh()->status)->toBe(OrderEditPermissionRequest::STATUS_ADMIN_APPROVED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_DISPATCHED);
});

it('lets admin reject a director-approved request and request again without changing order status', function () {
    $ctx = dispatchedOrderReadyForEdit();
    $request = app(RequestOrderEditPermission::class)->execute(
        order: $ctx['order'],
        actor: $ctx['admin'],
        reason: 'Need to fix vehicle number.',
    )['request'];

    app(ApproveOrderEditPermission::class)->execute($request->fresh(), $ctx['director']);

    $rejected = app(AdminRejectOrderEditPermission::class)->execute(
        order: $ctx['order']->fresh(),
        actor: $ctx['admin'],
        remark: 'Correction is no longer required.',
    )['request'];

    expect($rejected->status)->toBe(OrderEditPermissionRequest::STATUS_REJECTED)
        ->and($rejected->rejection_remark)->toBe('Correction is no longer required.')
        ->and($ctx['order']->fresh()->status)->toBe(Order::STATUS_DISPATCHED)
        ->and(Gate::forUser($ctx['admin'])->allows('correctDispatchedTransport', $ctx['order']->fresh()))->toBeFalse()
        ->and(Gate::forUser($ctx['admin'])->allows('requestDispatchedEdit', $ctx['order']->fresh()))->toBeTrue();
});
