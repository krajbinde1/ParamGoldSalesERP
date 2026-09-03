<?php

use App\Actions\PaymentRequests\DeletePaymentRequest;
use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Enums\UserRole;
use App\Filament\Resources\PaymentRequests\Pages\EditPaymentRequest;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function paymentEditAdmin(): User
{
    return User::query()->create([
        'name' => 'Payment Edit Admin',
        'email' => 'pay.edit.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

function paymentEditDirector(): User
{
    return User::query()->create([
        'name' => 'Payment Edit Director',
        'email' => 'pay.edit.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function paymentEditRequest(User $admin, array $overrides = []): PaymentRequest
{
    return PaymentRequest::query()->create(array_merge([
        'request_no' => 'PR-EDIT-'.uniqid(),
        'vendor_name' => 'ABC Traders',
        'vendor_mobile' => '9876543210',
        'amount' => 25000,
        'remark' => 'Services',
        'status' => PaymentRequest::STATUS_PENDING_FIRST,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ], $overrides));
}

function paymentEditLockAfterFirstApproval(PaymentRequest $request, User $director, string $status = PaymentRequest::STATUS_PENDING_SECOND): PaymentRequest
{
    $request->forceFill([
        'status' => $status,
        'first_approved_by' => $director->id,
        'first_approver_name' => $director->name,
        'first_approver_role' => 'Director',
        'first_approved_at' => now(),
    ])->save();

    return $request->fresh();
}

it('lets admin edit vendor details and add a supporting document before first approval', function (): void {
    Storage::fake('local');
    $admin = paymentEditAdmin();
    $request = paymentEditRequest($admin);

    Livewire::actingAs($admin)
        ->test(ListPaymentRequests::class)
        ->assertSuccessful()
        ->assertTableActionVisible('edit', $request)
        ->assertTableActionVisible('delete', $request);

    Livewire::actingAs($admin)
        ->test(EditPaymentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'vendor_name' => 'Updated Vendor',
            'vendor_mobile' => '9123456789',
            'amount' => 40000,
            'remark' => 'Updated remark',
            'supporting_documents' => [
                UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $request->fresh();
    expect($fresh->vendor_name)->toBe('Updated Vendor')
        ->and($fresh->vendor_mobile)->toBe('9123456789')
        ->and((float) $fresh->amount)->toBe(40000.0)
        ->and($fresh->remark)->toBe('Updated remark')
        ->and($fresh->status)->toBe(PaymentRequest::STATUS_PENDING_FIRST)
        ->and($fresh->supportingDocuments()->count())->toBe(1);
});

it('lets admin permanently delete a payment request before first approval', function (): void {
    Storage::fake('local');
    $admin = paymentEditAdmin();
    $request = paymentEditRequest($admin);
    $docs = app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [UploadedFile::fake()->create('quote.pdf', 80, 'application/pdf')],
    );
    $path = $docs[0]->stored_file_path;

    Storage::disk('local')->assertExists($path);

    Livewire::actingAs($admin)
        ->test(ListPaymentRequests::class)
        ->callTableAction('delete', $request)
        ->assertHasNoTableActionErrors();

    expect(PaymentRequest::withTrashed()->find($request->id))->toBeNull()
        ->and(PaymentRequestSupportingDocument::withTrashed()->where('payment_request_id', $request->id)->count())->toBe(0);

    Storage::disk('local')->assertMissing($path);
});

it('locks edit and delete after first approval, second approval, and payment done', function (): void {
    $admin = paymentEditAdmin();
    $director = paymentEditDirector();
    $pendingSecond = paymentEditLockAfterFirstApproval(
        paymentEditRequest($admin, ['request_no' => 'PR-LOCK-2']),
        $director,
        PaymentRequest::STATUS_PENDING_SECOND,
    );
    $approved = paymentEditLockAfterFirstApproval(
        paymentEditRequest($admin, ['request_no' => 'PR-LOCK-3']),
        $director,
        PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
    );
    $paid = paymentEditLockAfterFirstApproval(
        paymentEditRequest($admin, ['request_no' => 'PR-LOCK-4']),
        $director,
        PaymentRequest::STATUS_PAYMENT_DONE,
    );

    expect($admin->can('update', $pendingSecond))->toBeFalse()
        ->and($admin->can('delete', $pendingSecond))->toBeFalse()
        ->and($admin->can('update', $approved))->toBeFalse()
        ->and($admin->can('delete', $approved))->toBeFalse()
        ->and($admin->can('update', $paid))->toBeFalse()
        ->and($admin->can('delete', $paid))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(ListPaymentRequests::class)
        ->assertTableActionHidden('edit', $pendingSecond)
        ->assertTableActionHidden('delete', $pendingSecond)
        ->assertTableActionHidden('edit', $approved)
        ->assertTableActionHidden('delete', $approved)
        ->assertTableActionHidden('edit', $paid)
        ->assertTableActionHidden('delete', $paid);

    Livewire::actingAs($admin)
        ->test(EditPaymentRequest::class, ['record' => $pendingSecond->getRouteKey()])
        ->assertForbidden();

    expect(fn () => app(DeletePaymentRequest::class)->execute($pendingSecond, $admin))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $pendingSecond->vendor_name = 'Should Not Save';
    expect(fn () => $pendingSecond->save())->toThrow(ValidationException::class);
});

it('hides edit and delete from directors who are not admin', function (): void {
    $admin = paymentEditAdmin();
    $director = paymentEditDirector();
    $request = paymentEditRequest($admin);

    expect($director->can('update', $request))->toBeFalse()
        ->and($director->can('delete', $request))->toBeFalse();

    Livewire::actingAs($director)
        ->test(ListPaymentRequests::class)
        ->assertSuccessful()
        ->assertTableActionHidden('edit', $request)
        ->assertTableActionHidden('delete', $request);

    Livewire::actingAs($director)
        ->test(ViewPaymentRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('edit')
        ->assertActionHidden('delete');
});
