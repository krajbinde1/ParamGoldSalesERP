<?php

use App\Actions\PaymentRequests\AdminRejectPaymentRequest;
use App\Actions\PaymentRequests\ApprovePaymentRequest;
use App\Actions\PaymentRequests\RejectPaymentRequest;
use App\Enums\UserRole;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function paymentRejectAdmin(): User
{
    return User::query()->create([
        'name' => 'Payment Reject Admin',
        'email' => 'pay.reject.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

function paymentRejectDirector(): User
{
    return User::query()->create([
        'name' => 'Payment Reject Director',
        'email' => 'pay.reject.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function paymentRejectRequest(User $admin, array $overrides = []): PaymentRequest
{
    return PaymentRequest::query()->create(array_merge([
        'request_no' => 'PR-REJ-'.uniqid(),
        'vendor_name' => 'Reject Vendor',
        'vendor_mobile' => '9876543210',
        'amount' => 15000,
        'remark' => 'Office expense',
        'status' => PaymentRequest::STATUS_PENDING_FIRST,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ], $overrides));
}

it('lets admin reject a payment request before first approval with a mandatory reason', function (): void {
    $admin = paymentRejectAdmin();
    $request = paymentRejectRequest($admin);

    $updated = app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        reason: 'Duplicate vendor bill',
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($updated->isRejected())->toBeTrue()
        ->and($updated->isAdminRejected())->toBeTrue()
        ->and($updated->rejected_by)->toBe($admin->id)
        ->and($updated->rejected_by_name)->toBe($admin->name)
        ->and($updated->rejected_at)->not->toBeNull()
        ->and($updated->rejection_reason)->toBe('Duplicate vendor bill')
        ->and($updated->canBeFirstApproved())->toBeFalse()
        ->and($updated->canBeSecondApproved())->toBeFalse()
        ->and($updated->canBeMarkedPaid())->toBeFalse()
        ->and($updated->canBeRejectedByAdmin())->toBeFalse()
        ->and($updated->currentStageLabel())->toBe('Rejected')
        ->and($updated->paymentStatusLabel())->toBe('Rejected')
        ->and($updated->displayStatusLabel())->toBe('Rejected')
        ->and($updated->rejectionActorLabel())->toBe($admin->name.' (Admin)')
        ->and($updated->rejectionReasonLabel())->toBe('Duplicate vendor bill');

    $timeline = collect($updated->load('createdByUser')->approvalTimeline());
    expect($timeline->pluck('label')->all())->toBe(['Request Created', 'Rejected'])
        ->and($timeline->firstWhere('key', 'rejected')['is_rejection'])->toBeTrue()
        ->and($timeline->firstWhere('key', 'rejected')['remark'])->toBe('Duplicate vendor bill')
        ->and($timeline->firstWhere('key', 'rejected')['actor'])->toBe($admin->name);
});

it('lets admin reject after first approval and after both approvals', function (): void {
    $admin = paymentRejectAdmin();
    $director = paymentRejectDirector();

    $pendingSecond = paymentRejectRequest($admin, [
        'status' => PaymentRequest::STATUS_PENDING_SECOND,
        'first_approved_by' => $director->id,
        'first_approver_name' => $director->name,
        'first_approver_role' => 'Director',
        'first_approved_at' => now(),
    ]);

    $afterFirst = app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $pendingSecond,
        actor: $admin,
        reason: 'Vendor GST mismatch',
    );

    expect($afterFirst->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($afterFirst->canBeSecondApproved())->toBeFalse()
        ->and($afterFirst->firstApprovalStatusLabel())->toBe('Approved')
        ->and($afterFirst->secondApprovalStatusLabel())->toBe('—');

    $timeline = collect($afterFirst->load('createdByUser')->approvalTimeline())->pluck('label')->all();
    expect($timeline)->toBe(['Request Created', 'First Approval', 'Rejected']);

    $approved = paymentRejectRequest($admin, [
        'status' => PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
        'first_approved_by' => $director->id,
        'first_approver_name' => $director->name,
        'first_approver_role' => 'Director',
        'first_approved_at' => now(),
        'second_approved_by' => $director->id,
        'second_approver_name' => $director->name,
        'second_approver_role' => 'Director',
        'second_approved_at' => now(),
    ]);

    $afterBoth = app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $approved,
        actor: $admin,
        reason: 'Payment already made outside ERP',
    );

    expect($afterBoth->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($afterBoth->canBeMarkedPaid())->toBeFalse()
        ->and($afterBoth->secondApprovalStatusLabel())->toBe('Approved');

    $paidTimeline = collect($afterBoth->load('createdByUser')->approvalTimeline())->pluck('label')->all();
    expect($paidTimeline)->toBe(['Request Created', 'First Approval', 'Second Approval', 'Rejected'])
        ->and($paidTimeline)->not->toContain('Payment Done');
});

it('requires a rejection reason and blocks reject after payment done', function (): void {
    $admin = paymentRejectAdmin();
    $pending = paymentRejectRequest($admin);

    expect(fn () => app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $pending,
        actor: $admin,
        reason: 'ab',
    ))->toThrow(ValidationException::class);

    expect($pending->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_FIRST);

    $paid = paymentRejectRequest($admin, [
        'status' => PaymentRequest::STATUS_PAYMENT_DONE,
        'payment_done_by' => $admin->id,
        'payment_done_at' => now(),
        'payment_proof_path' => 'payment-request-proofs/demo.jpg',
    ]);

    expect($paid->canBeRejectedByAdmin())->toBeFalse()
        ->and($admin->can('reject', $paid))->toBeFalse();

    expect(fn () => app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $paid,
        actor: $admin,
        reason: 'Too late to reject',
    ))->toThrow(AuthorizationException::class);
});

it('stops director approval and payment done after admin rejection', function (): void {
    $admin = paymentRejectAdmin();
    $director = paymentRejectDirector();
    $request = paymentRejectRequest($admin);

    app(AdminRejectPaymentRequest::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        reason: 'Cancelled by accounts',
    );

    $rejected = $request->fresh();

    expect(fn () => app(ApprovePaymentRequest::class)->execute($rejected, $director))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(RejectPaymentRequest::class)->execute($rejected, $director, 'Director remark here'))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $rejected->markPaymentDone($admin, 'payment-request-proofs/demo.jpg'))
        ->toThrow(ValidationException::class);
});

it('keeps director first-approval reject on its existing status', function (): void {
    $admin = paymentRejectAdmin();
    $director = paymentRejectDirector();
    $request = paymentRejectRequest($admin);

    $request->rejectFirst($director, 'Director', 'Amount is incorrect');

    expect($request->fresh()->status)->toBe(PaymentRequest::STATUS_REJECTED_FIRST)
        ->and($request->fresh()->isAdminRejected())->toBeFalse()
        ->and($request->fresh()->first_rejection_remark)->toBe('Amount is incorrect')
        ->and($request->fresh()->currentStageLabel())->toBe('Rejected (First)');
});

it('shows reject in view and table for eligible requests and hides it after payment done', function (): void {
    $admin = paymentRejectAdmin();
    $director = paymentRejectDirector();
    $pending = paymentRejectRequest($admin);
    $approved = paymentRejectRequest($admin, [
        'status' => PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
        'first_approved_by' => $director->id,
        'first_approver_name' => $director->name,
        'first_approver_role' => 'Director',
        'first_approved_at' => now(),
        'second_approved_by' => $director->id,
        'second_approver_name' => $director->name,
        'second_approver_role' => 'Director',
        'second_approved_at' => now(),
    ]);
    $paid = paymentRejectRequest($admin, [
        'status' => PaymentRequest::STATUS_PAYMENT_DONE,
        'payment_done_by' => $admin->id,
        'payment_done_at' => now(),
        'payment_proof_path' => 'payment-request-proofs/demo.jpg',
    ]);

    Livewire::actingAs($admin)
        ->test(ListPaymentRequests::class)
        ->assertSuccessful()
        ->assertTableActionVisible('reject', $pending)
        ->assertTableActionVisible('reject', $approved)
        ->assertTableActionHidden('reject', $paid);

    Livewire::actingAs($admin)
        ->test(ViewPaymentRequest::class, ['record' => $pending->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('reject')
        ->assertActionHidden('markPaymentDone')
        ->callAction('reject', data: [
            'rejection_reason' => 'Wrong vendor selected',
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Rejected');

    expect($pending->fresh()->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($pending->fresh()->rejection_reason)->toBe('Wrong vendor selected');

    Livewire::actingAs($admin)
        ->test(ViewPaymentRequest::class, ['record' => $paid->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('reject');

    Livewire::actingAs($director)
        ->test(ViewPaymentRequest::class, ['record' => $approved->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('reject');
});
