<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UploadOrderReceivedCopy
{
    /**
     * Production Supervisor uploads the received/delivery acknowledgment copy
     * after an order has been dispatched.
     *
     * @return array{order: Order}
     */
    public function execute(Order $order, User $actor, UploadedFile $file): array
    {
        if (! Gate::forUser($actor)->allows('uploadReceivedCopy', $order)) {
            throw new AuthorizationException('You are not allowed to upload a received copy for this order.');
        }

        if (! $order->canUploadReceivedCopy()) {
            throw ValidationException::withMessages([
                'status' => 'Received copy can be uploaded only after the order is dispatched.',
            ]);
        }

        Validator::make(
            ['received_copy' => $file],
            ['received_copy' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240']],
        )->validate();

        return DB::transaction(function () use ($order, $actor, $file): array {
            $previous = $order->received_copy_path;
            $path = str_replace('\\', '/', $file->store('order-received-copies', 'public'));

            $order->storeReceivedCopy($path, $actor->id);

            if (filled($previous) && $previous !== $path) {
                Storage::disk('public')->delete($previous);
            }

            return [
                'order' => $order->fresh(),
            ];
        });
    }
};
