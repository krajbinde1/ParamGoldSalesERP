<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SendOrderForBilling
{
    /**
     * Production Supervisor prepares transport details and sends order for Admin billing.
     *
     * @return array{order: Order}
     */
    public function execute(
        Order $order,
        User $actor,
        string $vehicleNumber,
        float $transportFreight,
        ?string $transportRemark = null,
    ): array {
        if (! Gate::forUser($actor)->allows('sendForBill', $order)) {
            throw new AuthorizationException('You are not allowed to send this order for billing.');
        }

        if (! $order->canBeSentForBilling()) {
            throw ValidationException::withMessages([
                'status' => 'Only orders approved by Sales Manager can be sent for billing.',
            ]);
        }

        Validator::make(
            [
                'vehicle_number' => $vehicleNumber,
                'transport_freight' => $transportFreight,
                'transport_remark' => $transportRemark,
            ],
            [
                'vehicle_number' => ['required', 'string', 'max:50'],
                'transport_freight' => ['required', 'numeric', 'min:0'],
                'transport_remark' => ['nullable', 'string', 'max:2000'],
            ],
        )->validate();

        return DB::transaction(function () use ($order, $actor, $vehicleNumber, $transportFreight, $transportRemark): array {
            $order->sendForBilling(
                userId: $actor->id,
                vehicleNumber: $vehicleNumber,
                transportFreight: $transportFreight,
                transportRemark: $transportRemark,
            );

            return ['order' => $order->fresh()];
        });
    }
}
