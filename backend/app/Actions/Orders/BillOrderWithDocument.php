<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BillOrderWithDocument
{
    /**
     * Admin marks a pending-for-billing order as billed and uploads the bill document.
     *
     * @return array{order: Order}
     */
    public function execute(
        Order $order,
        User $actor,
        UploadedFile $bill,
        ?string $billNumber = null,
        ?string $remark = null,
        ?string $billDate = null,
    ): array {
        if (! Gate::forUser($actor)->allows('bill', $order)) {
            throw new AuthorizationException('You are not allowed to bill this order.');
        }

        if (! $order->canBeBilled()) {
            throw ValidationException::withMessages([
                'status' => 'Only pending-for-billing orders can be marked as billed.',
            ]);
        }

        Validator::make(
            [
                'bill' => $bill,
                'bill_number' => $billNumber,
                'billing_remark' => $remark,
                'bill_date' => $billDate,
            ],
            [
                'bill' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
                'bill_number' => ['nullable', 'string', 'max:100'],
                'billing_remark' => ['nullable', 'string', 'max:2000'],
                'bill_date' => ['nullable', 'date'],
            ],
        )->validate();

        return DB::transaction(function () use ($order, $actor, $bill, $billNumber, $remark, $billDate): array {
            $path = str_replace('\\', '/', $bill->store('order-bills', 'public'));

            $order->markAsBilled(
                userId: $actor->id,
                billPath: $path,
                billNumber: $billNumber,
                remark: $remark,
                billDate: $billDate,
            );

            return [
                'order' => $order->fresh(),
            ];
        });
    }
}
