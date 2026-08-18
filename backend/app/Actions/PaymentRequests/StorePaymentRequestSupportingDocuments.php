<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestSupportingDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StorePaymentRequestSupportingDocuments
{
    /**
     * @param  list<UploadedFile>  $files
     * @return list<PaymentRequestSupportingDocument>
     */
    public function execute(PaymentRequest $paymentRequest, User $actor, array $files): array
    {
        if ($files === []) {
            return [];
        }

        if (! $actor->can('manageSupportingDocuments', $paymentRequest)) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['You are not allowed to upload supporting documents for this request.'],
            ]);
        }

        $stored = [];

        DB::transaction(function () use ($paymentRequest, $actor, $files, &$stored): void {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $this->assertValidFile($file);

                $extension = strtolower((string) $file->getClientOriginalExtension());
                if ($extension === '' || ! in_array($extension, PaymentRequestSupportingDocument::ALLOWED_EXTENSIONS, true)) {
                    $extension = match ($file->getMimeType()) {
                        'application/pdf' => 'pdf',
                        'image/png' => 'png',
                        default => 'jpg',
                    };
                }

                $safeName = Str::uuid()->toString().'.'.$extension;
                $directory = 'payment-request-supporting/'.$paymentRequest->id;
                $path = $file->storeAs($directory, $safeName, PaymentRequestSupportingDocument::DISK);

                if (! is_string($path) || $path === '') {
                    throw ValidationException::withMessages([
                        'supporting_documents' => ['Unable to store supporting document.'],
                    ]);
                }

                $stored[] = PaymentRequestSupportingDocument::query()->create([
                    'payment_request_id' => $paymentRequest->id,
                    'original_file_name' => $this->safeOriginalName($file),
                    'stored_file_path' => str_replace('\\', '/', $path),
                    'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                    'file_size' => (int) $file->getSize(),
                    'uploaded_by' => $actor->id,
                ]);
            }
        });

        return $stored;
    }

    private function assertValidFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['One or more uploaded files are invalid.'],
            ]);
        }

        $maxBytes = PaymentRequestSupportingDocument::MAX_SIZE_KB * 1024;
        if ((int) $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['Each supporting document must be 10 MB or smaller.'],
            ]);
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        $mimeOk = in_array($mime, PaymentRequestSupportingDocument::ALLOWED_MIMES, true);
        $extOk = in_array($extension, PaymentRequestSupportingDocument::ALLOWED_EXTENSIONS, true);

        if (! $mimeOk || ! $extOk) {
            throw ValidationException::withMessages([
                'supporting_documents' => ['Only PDF, JPG, JPEG, and PNG files are allowed.'],
            ]);
        }
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());
        $name = preg_replace('/[^\w.\-\s()]+/u', '_', $name) ?: 'document';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document';
        }

        return Str::limit($name, 180, '');
    }
}
