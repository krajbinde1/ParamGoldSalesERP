<?php

use App\Actions\PaymentRequests\StorePaymentRequestSupportingDocuments;
use App\Enums\UserRole;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function makePaymentAdmin(): User
{
    return User::query()->create([
        'name' => 'Payment Admin',
        'email' => 'pay.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

function makeDirectorUser(string $name = 'Director One'): User
{
    return User::query()->create([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function makePendingPaymentRequest(User $admin): PaymentRequest
{
    return PaymentRequest::query()->create([
        'request_no' => 'PR-'.random_int(1000, 9999),
        'vendor_name' => 'ABC Traders',
        'vendor_mobile' => '9876543210',
        'amount' => 25000,
        'remark' => 'Services',
        'status' => PaymentRequest::STATUS_PENDING_FIRST,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ]);
}

it('stores multiple supporting documents with safe paths', function () {
    $admin = makePaymentAdmin();
    $request = makePendingPaymentRequest($admin);

    $docs = app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [
            UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->image('bank.jpg'),
        ],
    );

    expect($docs)->toHaveCount(2);
    expect(PaymentRequestSupportingDocument::query()->where('payment_request_id', $request->id)->count())->toBe(2);

    foreach ($docs as $doc) {
        expect($doc->stored_file_path)->not->toContain('invoice.pdf');
        expect($doc->stored_file_path)->toContain('payment-request-supporting/'.$request->id);
        Storage::disk('local')->assertExists($doc->stored_file_path);
    }
});

it('rejects invalid supporting document mime types', function () {
    $admin = makePaymentAdmin();
    $request = makePendingPaymentRequest($admin);

    app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [
            UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'),
        ],
    );
})->throws(\Illuminate\Validation\ValidationException::class);

it('allows director to stream supporting document and blocks unauthorized users', function () {
    $admin = makePaymentAdmin();
    $director = makeDirectorUser();
    $employee = User::query()->create([
        'name' => 'Employee',
        'email' => 'emp.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
    ]);

    $request = makePendingPaymentRequest($admin);
    $docs = app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [UploadedFile::fake()->create('quote.pdf', 120, 'application/pdf')],
    );
    $doc = $docs[0];

    $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-requests/'.$request->id.'/supporting-documents/'.$doc->id)
        ->assertOk();

    $this->actingAs($employee, 'sanctum')
        ->getJson('/api/director/payment-requests/'.$request->id.'/supporting-documents/'.$doc->id)
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(route('payment-requests.supporting-documents.show', [
            'paymentRequest' => $request->id,
            'supportingDocument' => $doc->id,
        ]))
        ->assertOk();

    $this->actingAs($employee)
        ->get(route('payment-requests.supporting-documents.show', [
            'paymentRequest' => $request->id,
            'supportingDocument' => $doc->id,
        ]))
        ->assertForbidden();
});

it('includes supporting_documents metadata on payment request detail', function () {
    $admin = makePaymentAdmin();
    $director = makeDirectorUser();
    $request = makePendingPaymentRequest($admin);

    app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [UploadedFile::fake()->image('proof.png')],
    );

    $response = $this->actingAs($director, 'sanctum')
        ->getJson('/api/director/payment-requests/'.$request->id)
        ->assertOk()
        ->json('data.supporting_documents');

    expect($response)->toBeArray()->toHaveCount(1);
    expect($response[0])->toHaveKeys(['id', 'file_name', 'mime_type', 'file_size', 'view_url', 'view_path']);
    expect($response[0]['view_url'])->not->toContain('storage/app');
    expect($response[0]['view_path'])->toStartWith('/director/payment-requests/');
    expect($response[0]['view_url'])->toContain('/api/director/payment-requests/');
    expect($response[0])->not->toHaveKey('stored_file_path');
});

it('streams document binary content with correct content type for director', function () {
    $admin = makePaymentAdmin();
    $director = makeDirectorUser();
    $request = makePendingPaymentRequest($admin);
    $docs = app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [UploadedFile::fake()->image('bank.png')],
    );
    $doc = $docs[0];

    Storage::disk('local')->assertExists($doc->stored_file_path);

    $response = $this->actingAs($director, 'sanctum')
        ->get('/api/director/payment-requests/'.$request->id.'/supporting-documents/'.$doc->id)
        ->assertOk();

    expect(strtolower((string) $response->headers->get('content-type')))->toContain('image/');
    expect(strtolower((string) $response->headers->get('content-disposition')))->toContain('inline');
});

it('blocks supporting document changes after first director approval', function () {
    $admin = makePaymentAdmin();
    $request = makePendingPaymentRequest($admin);

    $docs = app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        files: [UploadedFile::fake()->image('before.png')],
    );

    $request->forceFill([
        'status' => PaymentRequest::STATUS_PENDING_SECOND,
        'first_approved_by' => makeDirectorUser()->id,
        'first_approver_name' => 'Director One',
        'first_approver_role' => 'Director',
        'first_approved_at' => now(),
    ])->save();

    expect($request->fresh()->isLockedForAdminEdits())->toBeTrue();
    expect($admin->can('manageSupportingDocuments', $request->fresh()))->toBeFalse();

    expect(fn () => app(StorePaymentRequestSupportingDocuments::class)->execute(
        paymentRequest: $request->fresh(),
        actor: $admin,
        files: [UploadedFile::fake()->image('after.png')],
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(fn () => app(\App\Actions\PaymentRequests\DeletePaymentRequestSupportingDocument::class)->execute(
        document: $docs[0]->fresh(),
        actor: $admin,
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(fn () => $request->fresh()->update(['vendor_name' => 'Hacked Vendor']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    $timeline = $request->fresh()->load('createdByUser')->approvalTimeline();
    expect(collect($timeline)->pluck('label')->all())->toContain('Request Created', 'First Approval', 'Second Approval');
    expect(collect($timeline)->pluck('label')->implode(' '))->not->toContain('First Approval –');
});
