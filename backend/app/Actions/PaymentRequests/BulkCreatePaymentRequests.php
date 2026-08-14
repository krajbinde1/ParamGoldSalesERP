<?php

namespace App\Actions\PaymentRequests;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\Notifications\PaymentRequestPushNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BulkCreatePaymentRequests
{
    public function __construct(
        private readonly PaymentRequestPushNotifier $notifier = new PaymentRequestPushNotifier,
    ) {}

    /**
     * Create multiple individual payment requests in one admin submit.
     *
     * @param  list<array{vendor_name: string, vendor_mobile: string, amount: float|int|string, remark?: string|null}>  $rows
     * @return Collection<int, PaymentRequest>
     */
    public function execute(User $actor, array $rows): Collection
    {
        if (! Gate::forUser($actor)->allows('create', PaymentRequest::class)) {
            throw new AuthorizationException('You are not allowed to create payment requests.');
        }

        $validated = Validator::make(
            ['rows' => $rows],
            [
                'rows' => ['required', 'array', 'min:1', 'max:100'],
                'rows.*.vendor_name' => ['required', 'string', 'max:255'],
                'rows.*.vendor_mobile' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s]{8,20}$/'],
                'rows.*.amount' => ['required', 'numeric', 'min:0.01'],
                'rows.*.remark' => ['nullable', 'string', 'max:2000'],
            ],
        )->validate();

        $created = collect();

        // Create without per-row FCM; send one queue summary after the batch.
        PaymentRequest::withoutEvents(function () use ($actor, $validated, &$created): void {
            DB::transaction(function () use ($actor, $validated, &$created): void {
                foreach ($validated['rows'] as $row) {
                    $created->push(PaymentRequest::query()->create([
                        'request_no' => PaymentRequest::generateRequestNo(),
                        'vendor_name' => trim((string) $row['vendor_name']),
                        'vendor_mobile' => trim((string) $row['vendor_mobile']),
                        'amount' => (float) $row['amount'],
                        'remark' => isset($row['remark']) ? trim((string) $row['remark']) : null,
                        'status' => PaymentRequest::STATUS_PENDING_FIRST,
                        'created_by' => $actor->id,
                    ]));
                }
            });
        });

        $seed = $created->last();
        if ($seed instanceof PaymentRequest) {
            try {
                $this->notifier->notifyCreated($seed->fresh() ?? $seed);
            } catch (\Throwable) {
                // FCM must never fail payment workflow.
            }
        }

        return $created;
    }
}
