<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestPaymentProof;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MarkPaymentRequestPaid
{
    /**
     * @param  list<UploadedFile>|UploadedFile  $proofs
     */
    public function execute(
        PaymentRequest $paymentRequest,
        User $actor,
        UploadedFile|array $proofs,
        ?string $remark = null,
    ): PaymentRequest {
        if (! Gate::forUser($actor)->allows('markPaid', $paymentRequest)) {
            throw new AuthorizationException('You are not allowed to mark this payment as done.');
        }

        if (! $paymentRequest->canBeMarkedPaid() || $paymentRequest->isRejected()) {
            throw ValidationException::withMessages([
                'status' => $paymentRequest->isRejected()
                    ? ['Payment cannot be marked done because this request was rejected.']
                    : ['Payment can only be marked done after both approvals.'],
            ]);
        }

        $files = $this->normalizeFiles($proofs);
        PaymentRequestPaymentProof::assertUploadedFiles($files);

        $remark = filled($remark) ? trim((string) $remark) : null;
        if ($remark !== null && mb_strlen($remark) > 2000) {
            throw ValidationException::withMessages([
                'payment_remark' => ['Payment remark may not be greater than 2000 characters.'],
            ]);
        }

        return DB::transaction(function () use ($paymentRequest, $actor, $files, $remark): PaymentRequest {
            $storedPaths = [];

            foreach (array_values($files) as $index => $file) {
                $extension = $this->resolvedExtension($file);
                $safeName = Str::uuid()->toString().'.'.$extension;
                $directory = 'payment-request-proofs/'.$paymentRequest->id;
                $path = $file->storeAs($directory, $safeName, PaymentRequestPaymentProof::DISK);

                if (! is_string($path) || $path === '') {
                    throw ValidationException::withMessages([
                        'payment_proof' => ['Unable to store payment proof.'],
                    ]);
                }

                $path = str_replace('\\', '/', $path);
                $storedPaths[] = $path;

                PaymentRequestPaymentProof::query()->create([
                    'payment_request_id' => $paymentRequest->id,
                    'original_file_name' => $this->safeOriginalName($file),
                    'stored_file_path' => $path,
                    'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                    'file_size' => (int) $file->getSize(),
                    'sort_order' => $index,
                    'uploaded_by' => $actor->id,
                ]);
            }

            $paymentRequest->markPaymentDone(
                actor: $actor,
                proofPath: $storedPaths[0],
                remark: $remark,
            );

            return $paymentRequest->fresh(['paymentProofs']) ?? $paymentRequest;
        });
    }

    /**
     * @param  list<UploadedFile>|UploadedFile  $proofs
     * @return list<UploadedFile>
     */
    private function normalizeFiles(UploadedFile|array $proofs): array
    {
        $items = is_array($proofs) ? $proofs : [$proofs];

        return array_values(array_filter(
            $items,
            fn ($file): bool => $file instanceof UploadedFile,
        ));
    }

    private function resolvedExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== '' && in_array($extension, PaymentRequestPaymentProof::ALLOWED_EXTENSIONS, true)) {
            return $extension;
        }

        return match (strtolower((string) $file->getMimeType())) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename((string) $file->getClientOriginalName());
        $name = preg_replace('/[^\w.\-\s()]+/u', '_', $name) ?: 'payment-proof';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'payment-proof';
        }

        return Str::limit($name, 180, '');
    }
}
