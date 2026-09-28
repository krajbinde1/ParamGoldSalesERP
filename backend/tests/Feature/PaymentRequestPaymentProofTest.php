<?php

use App\Actions\PaymentRequests\MarkPaymentRequestPaid;
use App\Enums\UserRole;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestPaymentProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

function markPaidAdmin(): User
{
    return User::query()->create([
        'name' => 'Mark Paid Admin',
        'email' => 'pay.mark.paid.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

function temporaryPaymentProof(string $name, string $mime, string $contents): TemporaryUploadedFile
{
    config([
        'filesystems.default' => 'public',
        'livewire.temporary_file_upload.disk' => 'public',
    ]);

    $filename = 'proof-'.uniqid().'-'.$name;
    Storage::disk('public')->put('livewire-tmp/'.$filename, $contents);
    Storage::disk('public')->put('livewire-tmp/'.$filename.'.json', json_encode([
        'name' => $name,
        'type' => $mime,
    ]));

    return new TemporaryUploadedFile($filename, 'public');
}

function approvedPaymentRequest(User $admin): PaymentRequest
{
    return PaymentRequest::query()->create([
        'request_no' => 'PR-PAID-'.uniqid(),
        'vendor_name' => 'Proof Vendor',
        'vendor_mobile' => '9876543210',
        'amount' => 18000,
        'remark' => 'Office payment',
        'status' => PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
        'created_by' => $admin->id,
        'reminder_count' => 0,
    ]);
}

it('stores a single image as payment proof and marks payment done', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: UploadedFile::fake()->image('upi.jpg'),
        remark: 'Paid by UPI',
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->payment_remark)->toBe('Paid by UPI')
        ->and($updated->paymentProofs)->toHaveCount(1)
        ->and($updated->payment_proof_path)->toBe($updated->paymentProofs->first()->stored_file_path);

    Storage::disk('public')->assertExists($updated->payment_proof_path);
    expect($updated->paymentProofItems())->toHaveCount(1)
        ->and($updated->paymentProofItems()[0]['file_name'])->toBe('upi.jpg');
});

it('stores a single pdf payment proof', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf')],
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->paymentProofs)->toHaveCount(1)
        ->and($updated->paymentProofs->first()->isPdf())->toBeTrue();

    Storage::disk('public')->assertExists($updated->paymentProofs->first()->stored_file_path);
});

it('stores multiple image payment proofs', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            UploadedFile::fake()->image('shot-1.png'),
            UploadedFile::fake()->image('shot-2.jpg'),
            UploadedFile::fake()->create('shot-3.webp', 80, 'image/webp'),
        ],
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->paymentProofs)->toHaveCount(3)
        ->and($updated->paymentProofItems())->toHaveCount(3)
        ->and($updated->canBeMarkedPaid())->toBeFalse();

    foreach ($updated->paymentProofs as $proof) {
        Storage::disk('public')->assertExists($proof->stored_file_path);
        expect($proof->isImage())->toBeTrue();
    }
});

it('rejects mixed pdf and image payment proofs and empty uploads', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    expect(fn () => app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->image('shot.jpg'),
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            UploadedFile::fake()->create('one.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('two.pdf', 120, 'application/pdf'),
        ],
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(PaymentRequest::STATUS_APPROVED_FOR_PAYMENT)
        ->and(PaymentRequestPaymentProof::query()->where('payment_request_id', $request->id)->count())->toBe(0);
});

it('lets admin stream view and download payment proofs after payment done', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            UploadedFile::fake()->image('first.png'),
            UploadedFile::fake()->image('second.jpg'),
        ],
    );

    $proof = $updated->paymentProofs->first();

    $this->actingAs($admin)
        ->get(route('payment-requests.payment-proofs.show', [
            'paymentRequest' => $updated->id,
            'paymentProof' => $proof->id,
        ]))
        ->assertOk();

    $download = $this->actingAs($admin)
        ->get(route('payment-requests.payment-proofs.show', [
            'paymentRequest' => $updated->id,
            'paymentProof' => $proof->id,
            'download' => 1,
        ]));

    $download->assertOk();
    expect(strtolower((string) $download->headers->get('content-disposition')))->toContain('attachment');

    Livewire::actingAs($admin)
        ->test(ViewPaymentRequest::class, ['record' => $updated->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('first.png')
        ->assertSee('second.jpg')
        ->assertSee('Download');
});

it('marks payment done from one livewire image without moving the temporary upload', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);
    $image = UploadedFile::fake()->image('upi.jpg');
    $proof = temporaryPaymentProof('upi.jpg', 'image/jpeg', file_get_contents($image->getPathname()));

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: $proof,
        remark: 'Paid by UPI',
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->payment_remark)->toBe('Paid by UPI')
        ->and($updated->paymentProofs)->toHaveCount(1)
        ->and(Storage::disk('public')->exists('livewire-tmp/'.$proof->getFilename()))->toBeTrue();

    Storage::disk('public')->assertExists($updated->payment_proof_path);
});

it('marks payment done from multiple livewire images exactly once', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);

    $firstImage = UploadedFile::fake()->image('shot-1.png');
    $firstContents = file_get_contents($firstImage->getPathname());
    $secondImage = UploadedFile::fake()->image('shot-2.jpg');
    $secondContents = file_get_contents($secondImage->getPathname());

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            temporaryPaymentProof('shot-1.png', 'image/png', $firstContents),
            temporaryPaymentProof('shot-2.jpg', 'image/jpeg', $secondContents),
        ],
        remark: 'Two screenshots',
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->paymentProofs)->toHaveCount(2)
        ->and(PaymentRequestPaymentProof::query()->where('payment_request_id', $request->id)->count())->toBe(2);
});

it('marks payment done from one livewire pdf', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);
    $pdf = UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf');

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: temporaryPaymentProof('receipt.pdf', 'application/pdf', file_get_contents($pdf->getPathname())),
    );

    expect($updated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($updated->paymentProofs)->toHaveCount(1)
        ->and($updated->paymentProofs->first()->isPdf())->toBeTrue();
});

it('rejects a livewire pdf mixed with an image and a missing proof', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);
    $pdf = UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf');
    $image = UploadedFile::fake()->image('shot.jpg');

    expect(fn () => app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [
            temporaryPaymentProof('receipt.pdf', 'application/pdf', file_get_contents($pdf->getPathname())),
            temporaryPaymentProof('shot.jpg', 'image/jpeg', file_get_contents($image->getPathname())),
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: [],
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(PaymentRequest::STATUS_APPROVED_FOR_PAYMENT)
        ->and(PaymentRequestPaymentProof::query()->where('payment_request_id', $request->id)->count())->toBe(0);
});

it('does not create a second completion when confirm payment done is submitted twice', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);
    $image = UploadedFile::fake()->image('upi.jpg');
    $first = temporaryPaymentProof('upi.jpg', 'image/jpeg', file_get_contents($image->getPathname()));
    $second = temporaryPaymentProof('upi-again.jpg', 'image/jpeg', file_get_contents($image->getPathname()));

    $updated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $request,
        actor: $admin,
        proofs: $first,
        remark: 'Paid once',
    );

    $repeated = app(MarkPaymentRequestPaid::class)->execute(
        paymentRequest: $updated,
        actor: $admin,
        proofs: $second,
        remark: 'Paid again',
    );

    expect($repeated->id)->toBe($updated->id)
        ->and($repeated->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($repeated->payment_remark)->toBe('Paid once')
        ->and(PaymentRequestPaymentProof::query()->where('payment_request_id', $request->id)->count())->toBe(1)
        ->and(PaymentRequest::query()->whereKey($request->id)->where('status', PaymentRequest::STATUS_PAYMENT_DONE)->count())->toBe(1);
});

it('returns the payment request page successfully after confirm payment done', function (): void {
    Storage::fake('public');
    $admin = markPaidAdmin();
    $request = approvedPaymentRequest($admin);
    Livewire::actingAs($admin)
        ->test(ViewPaymentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('markPaymentDone', data: [
            'payment_remark' => 'Paid by UPI',
            'payment_proofs' => [UploadedFile::fake()->image('upi.jpg')],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Payment marked as done')
        ->assertSuccessful();

    $fresh = $request->fresh();

    expect($fresh->status)->toBe(PaymentRequest::STATUS_PAYMENT_DONE)
        ->and($fresh->payment_remark)->toBe('Paid by UPI')
        ->and(PaymentRequestPaymentProof::query()->where('payment_request_id', $request->id)->count())->toBe(1);

    Livewire::actingAs($admin)
        ->test(ViewPaymentRequest::class, ['record' => $fresh->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('upi.jpg')
        ->assertSee('Payment Done')
        ->assertActionHidden('markPaymentDone');
});
