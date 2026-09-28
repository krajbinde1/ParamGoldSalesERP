<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestPaymentProof;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
        if ($paymentRequest->status === PaymentRequest::STATUS_PAYMENT_DONE) {
            if (! $actor->isAdminUser()) {
                throw new AuthorizationException('You are not allowed to mark this payment as done.');
            }

            return $paymentRequest->fresh(['paymentProofs']) ?? $paymentRequest;
        }

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

        $prepared = array_map(function (UploadedFile $file): array {
            return [
                'file' => $file,
                'original_name' => $this->safeOriginalName($file),
                'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'file_size' => (int) $file->getSize(),
                'extension' => $this->resolvedExtension($file),
            ];
        }, array_values($files));

        return DB::transaction(function () use ($paymentRequest, $actor, $prepared, $remark): PaymentRequest {
            /** @var PaymentRequest|null $locked */
            $locked = PaymentRequest::query()
                ->whereKey($paymentRequest->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'status' => ['Payment request was not found.'],
                ]);
            }

            if ($locked->status === PaymentRequest::STATUS_PAYMENT_DONE) {
                return $locked->fresh(['paymentProofs']) ?? $locked;
            }

            if (! $locked->canBeMarkedPaid() || $locked->isRejected()) {
                throw ValidationException::withMessages([
                    'status' => $locked->isRejected()
                        ? ['Payment cannot be marked done because this request was rejected.']
                        : ['Payment can only be marked done after both approvals.'],
                ]);
            }

            $storedPaths = [];

            foreach ($prepared as $index => $proof) {
                /** @var UploadedFile $file */
                $file = $proof['file'];
                $safeName = Str::uuid()->toString().'.'.$proof['extension'];
                $path = $this->copyProof($file, 'payment-request-proofs/'.$locked->id, $safeName);
                $storedPaths[] = $path;

                PaymentRequestPaymentProof::query()->create([
                    'payment_request_id' => $locked->id,
                    'original_file_name' => $proof['original_name'],
                    'stored_file_path' => $path,
                    'mime_type' => $proof['mime_type'],
                    'file_size' => $proof['file_size'],
                    'sort_order' => $index,
                    'uploaded_by' => $actor->id,
                ]);
            }

            $locked->markPaymentDone(
                actor: $actor,
                proofPath: $storedPaths[0],
                remark: $remark,
            );

            return $locked->fresh(['paymentProofs']) ?? $locked;
        });
    }

    private function copyProof(UploadedFile $file, string $directory, string $filename): string
    {
        $path = trim($directory.'/'.$filename, '/');
        $source = $file->getRealPath();
        $stream = $source !== '' ? fopen($source, 'rb') : false;

        if ($stream === false) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Unable to store payment proof.'],
            ]);
        }

        try {
            $stored = Storage::disk(PaymentRequestPaymentProof::DISK)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored !== true) {
            throw ValidationException::withMessages([
                'payment_proof' => ['Unable to store payment proof.'],
            ]);
        }

        return str_replace('\\', '/', $path);
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
